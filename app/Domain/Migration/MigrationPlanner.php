<?php

namespace App\Domain\Migration;

use App\Domain\Migration\Planners\CircuitsPlanner;
use App\Domain\Migration\Planners\DcimPlanner;
use App\Domain\Migration\Planners\IpamPlanner;
use App\Domain\Migration\Planners\RelationsPlanner;
use App\Domain\Migration\Planners\TenancyPlanner;
use Illuminate\Support\Str;

class MigrationPlanner
{
    private array $actions = [];

    private array $conflicts = [];

    private array $warnings = [];

    private array $sourceActions = [];

    private array $customFieldActions = [];

    private array $choiceSetActions = [];

    private array $identityLinks = [];

    private array $writeSchema = [];

    private bool $writeSchemaKnown = false;

    private array $target;

    private MappingPolicy $policy;

    private ResourceRegistry $resources;

    public function __construct(
        private readonly ?TenancyPlanner $tenancyPlanner = null,
        private readonly ?DcimPlanner $dcimPlanner = null,
        private readonly ?CircuitsPlanner $circuitsPlanner = null,
        private readonly ?IpamPlanner $ipamPlanner = null,
        private readonly ?RelationsPlanner $relationsPlanner = null,
        ?ResourceRegistry $resources = null,
    ) {
        $this->resources = $resources ?? new ResourceRegistry;
    }

    public function plan(array $source, array $target, array $mapping = [], array $identityLinks = []): array
    {
        $this->actions = [];
        $this->conflicts = [];
        $this->warnings = array_values(array_merge($source['warnings'] ?? [], $target['warnings'] ?? []));
        $this->sourceActions = [];
        $this->customFieldActions = [];
        $this->choiceSetActions = [];
        $this->target = $target['objects'] ?? $target;
        $this->writeSchema = $target['write_schema'] ?? [];
        $this->writeSchemaKnown = array_key_exists('write_schema', $target);
        $this->policy = new MappingPolicy($mapping);
        $this->identityLinks = [];
        foreach ($identityLinks as $link) {
            if (! is_array($link) || ! isset($link['source_type'], $link['source_id'])) {
                continue;
            }
            $this->identityLinks[$this->linkKey((string) $link['source_type'], (string) $link['source_id'])] = $link;
        }

        foreach ($this->policy->validate() as $error) {
            $this->conflicts[] = ['reason' => 'invalid_mapping', 'message' => $error];
        }

        $sourceObjects = $source['objects'] ?? $source;
        $objects = $this->policy->schemaVersion() === 2
            ? $this->objectsForPolicy($sourceObjects)
            : $sourceObjects;
        $this->planCustomFields();
        if ($this->policy->schemaVersion() === 1) {
            $this->planVrfs($objects['vrfs'] ?? []);
            $this->planVlanGroups($objects['vlan_groups'] ?? []);
            $this->planVlans($objects['vlans'] ?? []);
            $this->planPrefixes($objects['prefixes'] ?? []);
            $this->planIpAddresses($objects['ip_addresses'] ?? []);
        } else {
            $tenancy = $this->tenancyPlanner ?? new TenancyPlanner;
            $dcim = $this->dcimPlanner ?? new DcimPlanner;
            $circuits = $this->circuitsPlanner ?? new CircuitsPlanner;
            $ipam = $this->ipamPlanner ?? new IpamPlanner;
            $relations = $this->relationsPlanner ?? new RelationsPlanner;
            foreach ([
                ...$tenancy->intents($objects, $this->policy),
                ...$dcim->intents($objects, $this->policy),
                ...$circuits->intents($objects, $this->policy),
                ...$ipam->intents($objects, $this->policy),
            ] as $intent) {
                $this->consumeIntent($intent);
            }
            foreach ($relations->relations($sourceObjects, $this->policy) as $relation) {
                $this->consumeRelation($relation);
            }
        }
        $this->detectExistingTargetConflicts();
        $this->detectDuplicateClaims();
        $this->sortActions();

        $preservation = [
            'decisions' => $this->policy->preservationRules(),
            'unmigrated' => $source['preserved'] ?? [],
            'custom_field_definitions' => $source['custom_fields'] ?? [],
            'source_records' => $this->sourceRecords($sourceObjects),
            'ignored' => array_values(array_filter(
                $this->actions,
                fn (array $action): bool => $action['operation'] === 'ignore',
            )),
        ];

        return [
            ...($this->policy->schemaVersion() === 2 ? ['schema_version' => 3] : []),
            'actions' => array_values($this->actions),
            'conflicts' => array_values($this->conflicts),
            'warnings' => array_values(array_unique($this->warnings)),
            'preservation' => $preservation,
        ];
    }

    private function consumeIntent(array $intent): void
    {
        if (isset($intent['issue']) && is_array($intent['issue'])) {
            $this->recordPlanningIssue($intent['issue']);

            return;
        }
        $targetType = (string) ($intent['targetType'] ?? '');
        $source = is_array($intent['source'] ?? null) ? $intent['source'] : [];
        if ($targetType === '' || $source === []) {
            $this->conflicts[] = ['reason' => 'invalid_planner_intent'];

            return;
        }
        $sourceType = (string) ($source['source_type'] ?? $targetType);
        if (! str_starts_with($sourceType, 'mapping_') && ! $this->policy->migrates($sourceType)) {
            $this->addIgnoredAction(
                $targetType,
                $source,
                $this->policy->objectPolicy($sourceType) === 'ignore'
                    ? "{$sourceType} was explicitly ignored by the mapping."
                    : "{$sourceType} is preserved until its object policy is approved.",
            );

            return;
        }

        $dependencies = [];
        $naturalKey = $this->resolveIntentReferences($intent['naturalKey'] ?? [], $dependencies);
        $payload = $this->resolveIntentReferences($intent['payload'] ?? [], $dependencies);
        $fieldResult = $this->policy->fieldResult($sourceType, [
            ...($source['legacy'] ?? []),
            ...$source,
        ]);
        foreach ($fieldResult['errors'] as $error) {
            $this->conflicts[] = [
                'reason' => 'invalid_field_value',
                'source_type' => $sourceType,
                'source_id' => $source['source_id'] ?? null,
                ...$error,
            ];
        }
        if (is_array($payload)) {
            $payload = array_replace($payload, $fieldResult['data']);
        }
        $customFields = $this->customFields($sourceType, $source);
        if ($customFields !== []) {
            $payload['custom_fields'] = $customFields;
            array_push($dependencies, ...$this->customFieldDependencies($sourceType));
        }
        $this->addAction(
            $targetType,
            $source,
            is_array($naturalKey) ? $naturalKey : [],
            is_array($payload) ? $payload : [],
            array_values(array_unique($dependencies)),
            (bool) ($intent['createApproved'] ?? true),
        );
    }

    private function objectsForPolicy(array $objects): array
    {
        $filtered = [];
        foreach ($objects as $collection => $items) {
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $sourceType = (string) ($item['source_type'] ?? rtrim((string) $collection, 's'));
                if ($this->policy->migrates($sourceType)) {
                    $filtered[$collection][] = $item;
                }
            }
        }

        $referenceTypes = [
            'tenant_source_id' => 'customer',
            'vrf_source_id' => 'vrf',
            'vlan_source_id' => 'vlan',
            'vlan_group_source_id' => 'vlan_group',
            'prefix_source_id' => 'prefix',
            'parent_source_id' => 'prefix',
            'location_source_id' => 'location',
            'rack_source_id' => 'rack',
            'device_source_id' => 'device',
            'interface_source_id' => 'interface',
            'provider_source_id' => 'provider',
            'type_source_id' => 'circuit_type',
        ];
        foreach ($filtered as &$items) {
            foreach ($items as &$item) {
                foreach ($referenceTypes as $field => $type) {
                    if (! $this->policy->migrates($type)) {
                        $item[$field] = null;
                    }
                }
            }
            unset($item);
        }
        unset($items);

        return $filtered;
    }

    private function consumeRelation(array $relation): void
    {
        if (isset($relation['issue']) && is_array($relation['issue'])) {
            $this->recordPlanningIssue($relation['issue']);

            return;
        }
        $source = is_array($relation['source'] ?? null) ? $relation['source'] : [];
        $subjectType = (string) ($relation['subject_type'] ?? '');
        $subjectSourceId = (string) ($relation['subject_source_id'] ?? '');
        $subjectAction = $this->sourceActions[$subjectType][$subjectSourceId] ?? null;
        if ($subjectAction === null || ($this->actions[$subjectAction]['operation'] ?? null) === 'ignore') {
            $this->conflicts[] = [
                'reason' => 'missing_relation_subject',
                'relation' => $relation['relation'] ?? null,
                'source_type' => $source['source_type'] ?? null,
                'source_id' => $source['source_id'] ?? null,
                'subject_type' => $subjectType,
                'subject_source_id' => $subjectSourceId,
            ];

            return;
        }
        $dependencies = [$subjectAction];
        $payload = $this->resolveIntentReferences($relation['payload'] ?? [], $dependencies);
        $actionKey = CanonicalJson::fingerprint([
            'relation' => $relation['relation'] ?? null,
            'source_type' => $source['source_type'] ?? null,
            'source_id' => $source['source_id'] ?? null,
            'subject_action' => $subjectAction,
            'payload' => $payload,
        ]);
        $this->actions[$actionKey] = [
            'action_key' => $actionKey,
            'operation' => 'relation',
            'relation' => $relation['relation'] ?? null,
            'source_type' => $source['source_type'] ?? 'relation',
            'source_id' => (string) ($source['source_id'] ?? $actionKey),
            'source_hash' => $source['source_hash'] ?? null,
            'target_type' => $subjectType,
            'subject_ref' => ['$ref' => $subjectAction],
            'natural_key' => [],
            'payload' => is_array($payload) ? $payload : [],
            'payload_hash' => CanonicalJson::fingerprint($payload),
            'dependencies' => array_values(array_unique($dependencies)),
            'target_id' => null,
            'differences' => [],
        ];
    }

    private function resolveIntentReferences(mixed $value, array &$dependencies): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (isset($value['$source_ref']) && is_array($value['$source_ref'])) {
            $reference = $this->reference(
                (string) ($value['$source_ref']['target_type'] ?? ''),
                isset($value['$source_ref']['source_id']) ? (string) $value['$source_ref']['source_id'] : null,
            );
            array_push($dependencies, ...$this->dependencies($reference));

            return $reference;
        }
        $resolved = [];
        foreach ($value as $key => $item) {
            $resolved[$key] = $this->resolveIntentReferences($item, $dependencies);
        }

        return $resolved;
    }

    private function recordPlanningIssue(array $issue): void
    {
        $warningReasons = [
            'prefix_folder_preserved',
            'netbox_prefix_zero_length_preserved',
            'netbox_ip_address_zero_length_preserved',
            'vlan_vid_invalid_preserved',
            'vlan_vid_out_of_range_preserved',
            'device_ip_without_port',
            'ip_dns_name_invalid_preserved',
            'pat_preserved',
            'nat_cross_vrf_preserved',
            'nat_many_to_many_preserved',
            'nat_ip_pair_required',
            'nat_confirmation_required',
            'primary_ip_ambiguous',
            'customer_contact_invalid_preserved',
        ];
        if (in_array($issue['reason'] ?? null, $warningReasons, true)) {
            $this->warnings[] = CanonicalJson::encode($issue);

            return;
        }
        $this->conflicts[] = $issue;
    }

    private function planVrfs(array $objects): void
    {
        foreach ($objects as $object) {
            $naturalKey = ($object['rd'] ?? null)
                ? ['rd' => $object['rd'], 'name' => $object['name']]
                : ['rd' => null, 'name' => $object['name']];
            $payload = array_filter([
                'name' => $object['name'],
                'rd' => $object['rd'] ?? null,
                'description' => $object['description'] ?? '',
                'custom_fields' => $this->customFields('vrf', $object),
            ], fn (mixed $value): bool => $value !== null && $value !== []);
            $this->addAction('vrf', $object, $naturalKey, $payload, $this->customFieldDependencies('vrf'));
        }
    }

    private function planVlanGroups(array $objects): void
    {
        foreach ($objects as $object) {
            $name = $object['name'] ?? '';
            $naturalKey = ['name' => $name, 'scope_id' => null];
            $payload = array_filter([
                'name' => $name,
                'slug' => Str::slug($name) ?: 'phpipam-'.$object['source_id'],
                'description' => $object['description'] ?? '',
                'custom_fields' => $this->customFields('vlan_group', $object),
            ], fn (mixed $value): bool => $value !== []);
            $this->addAction('vlan_group', $object, $naturalKey, $payload, $this->customFieldDependencies('vlan_group'));
        }
    }

    private function planVlans(array $objects): void
    {
        foreach ($objects as $object) {
            $vid = $object['vid'] ?? null;
            if (! is_int($vid) && ! (is_string($vid) && ctype_digit($vid))) {
                $this->addIgnoredAction('vlan', $object, 'vlan_vid_invalid_preserved: VLAN ID is not an integer in the NetBox-supported range.');

                continue;
            }
            if ((int) $vid < 1 || (int) $vid > 4094) {
                $this->addIgnoredAction('vlan', $object, 'vlan_vid_out_of_range_preserved: VLAN ID must be between 1 and 4094 for NetBox.');

                continue;
            }
            $groupReference = $this->reference('vlan_group', $object['vlan_group_source_id'] ?? null);
            $dependencies = [
                ...$this->dependencies($groupReference),
                ...$this->customFieldDependencies('vlan'),
            ];
            $naturalKey = ['vid' => (int) $vid, 'group_id' => $groupReference];
            $payload = array_filter([
                'vid' => (int) $vid,
                'name' => $object['name'] ?: 'VLAN '.$vid,
                'status' => $this->policy->status('vlan', $object['source_status'] ?? null),
                'group' => $groupReference,
                'description' => $object['description'] ?? '',
                'custom_fields' => $this->customFields('vlan', $object),
            ], fn (mixed $value): bool => $value !== null && $value !== []);
            $this->addAction('vlan', $object, $naturalKey, $payload, $dependencies);
        }
    }

    private function planPrefixes(array $objects): void
    {
        usort($objects, fn (array $left, array $right): int => [
            $left['vrf_source_id'] ?? '',
            $this->prefixLength($left['prefix'] ?? null),
            $left['prefix'] ?? '',
        ] <=> [
            $right['vrf_source_id'] ?? '',
            $this->prefixLength($right['prefix'] ?? null),
            $right['prefix'] ?? '',
        ]);

        foreach ($objects as $object) {
            if (($object['is_folder'] ?? false) === true) {
                $this->addIgnoredAction('prefix', $object, 'phpIPAM folder has no safe automatic NetBox equivalent.');

                continue;
            }
            if ($this->prefixLength($object['prefix'] ?? null) === 0) {
                $this->addIgnoredAction(
                    'prefix',
                    $object,
                    'netbox_prefix_zero_length_preserved: NetBox does not accept a zero-length prefix; preserved for audit.',
                );

                continue;
            }

            $vrfReference = $this->reference('vrf', $object['vrf_source_id'] ?? null);
            $vlanReference = $this->reference('vlan', $object['vlan_source_id'] ?? null);
            $dependencies = [
                ...$this->dependencies($vrfReference),
                ...$this->dependencies($vlanReference),
                ...$this->customFieldDependencies('prefix'),
            ];
            $naturalKey = [
                'prefix' => $object['prefix'],
                'vrf_id' => $vrfReference,
            ];
            $payload = array_filter([
                'prefix' => $object['prefix'],
                'status' => $this->policy->status('prefix', $object['source_status'] ?? null),
                'vrf' => $vrfReference,
                'vlan' => $vlanReference,
                'description' => $object['description'] ?? '',
                'is_pool' => $object['is_pool'] ?? false,
                'mark_utilized' => $object['mark_utilized'] ?? false,
                'custom_fields' => $this->customFields('prefix', $object),
            ], fn (mixed $value): bool => $value !== null && $value !== []);
            $this->addAction('prefix', $object, $naturalKey, $payload, array_values(array_unique($dependencies)));
        }
    }

    private function planIpAddresses(array $objects): void
    {
        foreach ($objects as $object) {
            if ($this->prefixLength($object['address'] ?? null) === 0) {
                $this->addIgnoredAction(
                    'ip_address',
                    $object,
                    'netbox_ip_address_zero_length_preserved: NetBox does not accept a zero-length IP address; preserved for audit.',
                );

                continue;
            }
            $vrfReference = $this->reference('vrf', $object['vrf_source_id'] ?? null);
            $prefixReference = $this->reference('prefix', $object['prefix_source_id'] ?? null);
            $dependencies = [
                ...$this->dependencies($vrfReference),
                ...$this->dependencies($prefixReference),
                ...$this->customFieldDependencies('ip_address'),
            ];
            $naturalKey = [
                'address' => $object['address'],
                'vrf_id' => $vrfReference,
            ];
            $payload = array_filter([
                'address' => $object['address'],
                'status' => $this->policy->status('ip_address', $object['source_status'] ?? null),
                'vrf' => $vrfReference,
                'dns_name' => $object['dns_name'] ?? '',
                'description' => $object['description'] ?? '',
                'custom_fields' => $this->customFields('ip_address', $object),
            ], fn (mixed $value): bool => $value !== null && $value !== []);
            $this->addAction('ip_address', $object, $naturalKey, $payload, array_values(array_unique($dependencies)));
        }
    }

    private function addAction(
        string $targetType,
        array $source,
        array $naturalKey,
        array $payload,
        array $dependencies = [],
        bool $createApproved = true,
    ): void {
        $sourceId = (string) ($source['source_id'] ?? '');
        if ($sourceId === '' || $this->naturalKeyIsIncomplete($naturalKey)) {
            $this->conflicts[] = [
                'reason' => 'missing_identity',
                'source_type' => $source['source_type'] ?? $targetType,
                'source_id' => $sourceId,
                'natural_key' => $naturalKey,
            ];

            return;
        }

        $actionKey = CanonicalJson::fingerprint([
            'source_type' => $source['source_type'] ?? $targetType,
            'source_id' => $sourceId,
            'target_type' => $targetType,
            'natural_key' => $naturalKey,
        ]);

        if (isset($this->sourceActions[$targetType][$sourceId])) {
            $this->conflicts[] = [
                'reason' => 'duplicate_source_identity',
                'source_type' => $source['source_type'] ?? $targetType,
                'source_id' => $sourceId,
                'target_type' => $targetType,
            ];

            return;
        }

        if ($this->policy->ignores($source['source_type'] ?? $targetType)) {
            $operation = 'ignore';
            $matches = [];
            $matchedBy = 'mapping';
        } else {
            $sourceType = (string) ($source['source_type'] ?? $targetType);
            $link = $this->identityLinks[$this->linkKey($sourceType, $sourceId)] ?? null;
            if ($link !== null && ($link['target_type'] ?? null) !== $targetType) {
                $this->conflicts[] = [
                    'reason' => 'linked_target_type_mismatch',
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'expected_target_type' => $targetType,
                    'linked_target_type' => $link['target_type'] ?? null,
                ];

                return;
            }

            if ($link !== null) {
                $matches = array_values(array_filter(
                    $this->targetCollection($targetType),
                    fn (array $target): bool => (int) ($target['id'] ?? 0) === (int) ($link['target_id'] ?? 0),
                ));
                if ($matches === []) {
                    $this->conflicts[] = [
                        'reason' => 'linked_target_missing',
                        'source_type' => $sourceType,
                        'source_id' => $sourceId,
                        'target_type' => $targetType,
                        'target_id' => $link['target_id'] ?? null,
                    ];

                    return;
                }
                $matchedBy = 'object_link';
            } else {
                $matches = $this->targetMatches($targetType, $naturalKey);
                $matchedBy = $matches === [] ? 'none' : 'natural_key';
            }
            if (count($matches) > 1) {
                $this->conflicts[] = [
                    'reason' => 'ambiguous_target',
                    'source_type' => $source['source_type'] ?? $targetType,
                    'source_id' => $sourceId,
                    'target_type' => $targetType,
                    'natural_key' => $naturalKey,
                    'target_ids' => array_column($matches, 'id'),
                ];

                return;
            }
            if ($matches === [] && ! $createApproved) {
                $this->conflicts[] = [
                    'reason' => 'auxiliary_creation_unapproved',
                    'source_type' => $source['source_type'] ?? $targetType,
                    'source_id' => $sourceId,
                    'target_type' => $targetType,
                    'natural_key' => $naturalKey,
                ];

                return;
            }
            $operation = $matches === [] ? 'create' : 'reuse';
        }

        $existing = $matches[0] ?? null;
        $differences = $existing === null ? [] : $this->differences($payload, $existing);
        $allowedUpdates = $this->policy->allowedUpdates($targetType);
        $updatePayload = array_intersect_key($differences, array_flip($allowedUpdates));
        if (array_key_exists('assigned_object_id', $updatePayload)
            && array_key_exists('assigned_object_type', $payload)
            && in_array('assigned_object_type', $allowedUpdates, true)) {
            $updatePayload['assigned_object_type'] = $payload['assigned_object_type'];
        }
        if (array_key_exists('assigned_object_type', $updatePayload)
            && array_key_exists('assigned_object_id', $payload)
            && in_array('assigned_object_id', $allowedUpdates, true)) {
            $updatePayload['assigned_object_id'] = $payload['assigned_object_id'];
        }
        if ($existing !== null && $updatePayload !== []) {
            $operation = 'update';
            $payload = $updatePayload;
        } elseif ($differences !== []) {
            $this->warnings[] = "{$targetType} {$sourceId} exists in NetBox with differences; reuse keeps existing values.";
        }
        if (in_array($operation, ['create', 'update'], true)
            && ! $this->payloadIsCompatible($targetType, $source, $payload, $operation)
        ) {
            return;
        }

        $action = [
            'action_key' => $actionKey,
            'operation' => $operation,
            'source_type' => $source['source_type'] ?? $targetType,
            'source_id' => $sourceId,
            'source_hash' => $source['source_hash'] ?? null,
            'target_type' => $targetType,
            'natural_key' => $naturalKey,
            'payload' => $payload,
            'payload_hash' => CanonicalJson::fingerprint($payload),
            'dependencies' => array_values(array_unique($dependencies)),
            'target_id' => $existing['id'] ?? null,
            'matched_by' => $matchedBy,
            'target_last_updated' => $existing['last_updated'] ?? null,
            'differences' => $differences,
        ];
        $this->actions[$actionKey] = $action;
        $this->sourceActions[$targetType][$sourceId] = $actionKey;
    }

    private function planCustomFields(): void
    {
        $objectTypes = [
            'customer' => 'tenancy.tenant',
            'tag' => 'extras.tag',
            'location' => 'dcim.location',
            'rack' => 'dcim.rack',
            'device' => 'dcim.device',
            'interface' => 'dcim.interface',
            'provider' => 'circuits.provider',
            'circuit' => 'circuits.circuit',
            'asn' => 'ipam.asn',
            'vrf' => 'ipam.vrf',
            'vlan_group' => 'ipam.vlangroup',
            'vlan' => 'ipam.vlan',
            'prefix' => 'ipam.prefix',
            'ip_address' => 'ipam.ipaddress',
        ];

        $grouped = [];
        foreach ($this->policy->customFieldRules() as $rule) {
            $sourceType = $rule['source_type'] ?? null;
            $targetName = $rule['target'] ?? null;
            if (($rule['action'] ?? 'ignore') === 'ignore'
                || ! is_string($sourceType)
                || ! isset($objectTypes[$sourceType])
                || ! is_string($targetName)
                || $targetName === ''
            ) {
                continue;
            }
            $grouped[$targetName][] = $rule;
        }

        foreach ($grouped as $targetName => $rules) {
            $sourceTypes = array_values(array_unique(array_column($rules, 'source_type')));
            $dataTypes = array_values(array_unique(array_map(
                fn (array $rule): string => (string) ($rule['data_type'] ?? 'text'),
                $rules,
            )));
            if (count($dataTypes) !== 1) {
                $this->conflicts[] = [
                    'reason' => 'custom_field_mapping_type_conflict',
                    'target' => $targetName,
                    'types' => $dataTypes,
                ];

                continue;
            }
            $requiredObjectTypes = array_map(
                fn (string $sourceType): string => $objectTypes[$sourceType],
                $sourceTypes,
            );
            $existing = collect($this->target['custom_fields'] ?? [])
                ->first(fn (array $field): bool => (string) ($field['name'] ?? '') === $targetName);
            if ($existing !== null) {
                $expectedType = $dataTypes[0];
                if ((string) ($existing['type']['value'] ?? $existing['type'] ?? '') !== $expectedType) {
                    $this->conflicts[] = [
                        'reason' => 'custom_field_type_mismatch',
                        'source_types' => $sourceTypes,
                        'target' => $targetName,
                        'expected_type' => $expectedType,
                        'actual_type' => $existing['type']['value'] ?? $existing['type'] ?? null,
                    ];

                    continue;
                }
                $assignedTypes = array_map(
                    fn (mixed $type): string => is_array($type) ? (string) ($type['value'] ?? $type['display'] ?? '') : (string) $type,
                    $existing['object_types'] ?? [],
                );
                $missingTypes = array_values(array_diff($requiredObjectTypes, $assignedTypes));
                if ($missingTypes !== []) {
                    $this->conflicts[] = [
                        'reason' => 'custom_field_scope_mismatch',
                        'source_types' => $sourceTypes,
                        'target' => $targetName,
                        'missing_object_types' => $missingTypes,
                    ];

                    continue;
                }
            }

            $source = [
                'source_type' => 'mapping_custom_field',
                'source_id' => $targetName,
                'source_hash' => CanonicalJson::fingerprint($rules),
            ];
            $payload = [
                'name' => $targetName,
                'label' => (string) ($rules[0]['label'] ?? $targetName),
                'type' => $dataTypes[0],
                'object_types' => $requiredObjectTypes,
                'description' => (string) ($rules[0]['description'] ?? 'Migrated by IpamFerry'),
            ];
            $dependencies = [];
            if ($dataTypes[0] === 'select') {
                $choiceSet = $rules[0]['choice_set'] ?? null;
                if (! is_array($choiceSet) || ! is_string($choiceSet['name'] ?? null) || ! is_array($choiceSet['choices'] ?? null)) {
                    $this->conflicts[] = [
                        'reason' => 'custom_field_choice_set_missing',
                        'target' => $targetName,
                    ];

                    continue;
                }
                $choiceSetName = trim($choiceSet['name']);
                if (! isset($this->choiceSetActions[$choiceSetName])) {
                    $this->addAction(
                        'custom_field_choice_set',
                        [
                            'source_type' => 'mapping_custom_field_choice_set',
                            'source_id' => $choiceSetName,
                            'source_hash' => CanonicalJson::fingerprint($choiceSet),
                        ],
                        ['name' => $choiceSetName],
                        [
                            'name' => $choiceSetName,
                            'extra_choices' => array_map(
                                fn (mixed $choice): array => [(string) $choice, (string) $choice],
                                $choiceSet['choices'],
                            ),
                        ],
                        [],
                        $choiceSet['approved'] === true,
                    );
                    $this->choiceSetActions[$choiceSetName] = $this->sourceActions['custom_field_choice_set'][$choiceSetName] ?? null;
                }
                $choiceSetAction = $this->choiceSetActions[$choiceSetName] ?? null;
                if ($choiceSetAction === null) {
                    continue;
                }
                $choiceSetTargetId = $this->actions[$choiceSetAction]['target_id'] ?? null;
                $payload['choice_set'] = $choiceSetTargetId === null ? ['$ref' => $choiceSetAction] : (int) $choiceSetTargetId;
                if ($choiceSetTargetId === null) {
                    $dependencies[] = $choiceSetAction;
                }
            }
            $this->addAction('custom_field', $source, ['name' => $targetName], $payload, $dependencies);
            $actionKey = $this->sourceActions['custom_field'][$targetName] ?? null;
            if ($actionKey !== null) {
                foreach ($sourceTypes as $sourceType) {
                    $this->customFieldActions[$sourceType][] = $actionKey;
                }
            }
        }
    }

    private function customFieldDependencies(string $sourceType): array
    {
        return array_values(array_unique($this->customFieldActions[$sourceType] ?? []));
    }

    private function addIgnoredAction(string $targetType, array $source, string $reason): void
    {
        $sourceId = (string) ($source['source_id'] ?? '');
        if (isset($this->sourceActions[$targetType][$sourceId])) {
            $this->conflicts[] = [
                'reason' => 'duplicate_source_identity',
                'source_type' => $source['source_type'] ?? $targetType,
                'source_id' => $sourceId,
                'target_type' => $targetType,
            ];

            return;
        }
        $actionKey = CanonicalJson::fingerprint([
            'source_type' => $source['source_type'] ?? $targetType,
            'source_id' => $sourceId,
            'target_type' => $targetType,
            'ignored' => true,
        ]);
        $this->actions[$actionKey] = [
            'action_key' => $actionKey,
            'operation' => 'ignore',
            'source_type' => $source['source_type'] ?? $targetType,
            'source_id' => $sourceId,
            'source_hash' => $source['source_hash'] ?? null,
            'target_type' => $targetType,
            'natural_key' => [],
            'payload' => [],
            'payload_hash' => CanonicalJson::fingerprint([]),
            'dependencies' => [],
            'target_id' => null,
            'differences' => [],
            'reason' => $reason,
        ];
        $this->sourceActions[$targetType][$sourceId] = $actionKey;
        $this->warnings[] = $reason;
    }

    private function reference(string $targetType, ?string $sourceId): array|int|null
    {
        if ($sourceId === null) {
            return null;
        }

        $actionKey = $this->sourceActions[$targetType][$sourceId] ?? null;
        if ($actionKey === null) {
            $this->conflicts[] = [
                'reason' => 'missing_dependency',
                'target_type' => $targetType,
                'source_id' => $sourceId,
            ];

            return ['$missing' => "{$targetType}:{$sourceId}"];
        }
        if (($this->actions[$actionKey]['operation'] ?? null) === 'ignore') {
            $this->conflicts[] = [
                'reason' => 'preserved_dependency',
                'target_type' => $targetType,
                'source_id' => $sourceId,
            ];

            return ['$missing' => "{$targetType}:{$sourceId}"];
        }

        $action = $this->actions[$actionKey];
        $targetId = $action['target_id'] ?? null;

        // A pre-existing target can be referenced directly only when the
        // planned action is a true reuse. An update still changes the target
        // state, and every dependent relationship (for example a device
        // primary IP) must wait for that PATCH to succeed.
        if (($action['operation'] ?? null) !== 'reuse' || $targetId === null) {
            return ['$ref' => $actionKey];
        }

        return (int) $targetId;
    }

    private function dependencies(array|int|null $reference): array
    {
        return is_array($reference) && isset($reference['$ref']) ? [$reference['$ref']] : [];
    }

    private function targetMatches(string $targetType, array $naturalKey): array
    {
        if ($this->containsReference($naturalKey)) {
            return [];
        }

        $collection = $this->targetCollection($targetType);
        $relatedId = fn (mixed $value): ?int => is_array($value) ? ($value['id'] ?? null) : ($value === null ? null : (int) $value);

        return array_values(array_filter($collection, function (array $target) use ($targetType, $naturalKey, $relatedId): bool {
            return match ($targetType) {
                'tenant', 'site', 'manufacturer', 'device_role', 'provider', 'circuit_type', 'rir', 'tag', 'contact_role' => (string) ($target['slug'] ?? '') === (string) ($naturalKey['slug'] ?? ''),
                'device_type' => (string) ($target['slug'] ?? '') === (string) ($naturalKey['slug'] ?? '')
                    && $relatedId($target['manufacturer'] ?? null) === ($naturalKey['manufacturer_id'] ?? null),
                'contact' => (string) ($target['name'] ?? '') === (string) ($naturalKey['name'] ?? '')
                    && (string) ($target['email'] ?? '') === (string) ($naturalKey['email'] ?? ''),
                'contact_assignment' => (string) ($target['object_type'] ?? '') === (string) ($naturalKey['object_type'] ?? '')
                    && $relatedId($target['object'] ?? $target['object_id'] ?? null) === ($naturalKey['object_id'] ?? null)
                    && $relatedId($target['contact'] ?? null) === ($naturalKey['contact_id'] ?? null)
                    && $relatedId($target['role'] ?? null) === ($naturalKey['role_id'] ?? null),
                'location' => (string) ($target['slug'] ?? '') === (string) ($naturalKey['slug'] ?? '')
                    && $relatedId($target['site'] ?? null) === ($naturalKey['site_id'] ?? null)
                    && $relatedId($target['parent'] ?? null) === ($naturalKey['parent_id'] ?? null),
                'rack' => mb_strtolower((string) ($target['name'] ?? '')) === mb_strtolower((string) ($naturalKey['name'] ?? ''))
                    && $relatedId($target['site'] ?? null) === ($naturalKey['site_id'] ?? null)
                    && $relatedId($target['location'] ?? null) === ($naturalKey['location_id'] ?? null),
                'device' => mb_strtolower((string) ($target['name'] ?? '')) === mb_strtolower((string) ($naturalKey['name'] ?? ''))
                    && $relatedId($target['site'] ?? null) === ($naturalKey['site_id'] ?? null),
                'interface' => (string) ($target['name'] ?? '') === (string) ($naturalKey['name'] ?? '')
                    && $relatedId($target['device'] ?? null) === ($naturalKey['device_id'] ?? null),
                'mac_address' => strtoupper((string) ($target['mac_address'] ?? '')) === strtoupper((string) ($naturalKey['mac_address'] ?? '')),
                'circuit' => (string) ($target['cid'] ?? '') === (string) ($naturalKey['cid'] ?? '')
                    && $relatedId($target['provider'] ?? null) === ($naturalKey['provider_id'] ?? null),
                'circuit_termination' => $relatedId($target['circuit'] ?? null) === ($naturalKey['circuit_id'] ?? null)
                    && (string) ($target['term_side']['value'] ?? $target['term_side'] ?? '') === (string) ($naturalKey['term_side'] ?? ''),
                'asn' => (int) ($target['asn'] ?? 0) === (int) ($naturalKey['asn'] ?? -1),
                'vrf' => ($naturalKey['rd'] ?? null)
                    ? (string) ($target['rd'] ?? '') === (string) $naturalKey['rd']
                    : mb_strtolower((string) ($target['name'] ?? '')) === mb_strtolower((string) $naturalKey['name']),
                'vlan_group' => mb_strtolower((string) ($target['name'] ?? '')) === mb_strtolower((string) $naturalKey['name'])
                    && $relatedId($target['scope'] ?? null) === ($naturalKey['scope_id'] ?? null),
                'vlan' => (int) ($target['vid'] ?? -1) === (int) $naturalKey['vid']
                    && $relatedId($target['group'] ?? null) === ($naturalKey['group_id'] ?? null),
                'prefix' => (string) ($target['prefix'] ?? '') === (string) $naturalKey['prefix']
                    && $relatedId($target['vrf'] ?? null) === ($naturalKey['vrf_id'] ?? null),
                'ip_address' => (string) ($target['address'] ?? '') === (string) $naturalKey['address']
                    && $relatedId($target['vrf'] ?? null) === ($naturalKey['vrf_id'] ?? null),
                'custom_field' => (string) ($target['name'] ?? '') === (string) $naturalKey['name'],
                'custom_field_choice_set' => (string) ($target['name'] ?? '') === (string) $naturalKey['name'],
                default => false,
            };
        }));
    }

    private function targetCollection(string $targetType): array
    {
        try {
            $collection = $this->resources->collection($targetType);
        } catch (\InvalidArgumentException) {
            return [];
        }

        return is_array($this->target[$collection] ?? null) ? $this->target[$collection] : [];
    }

    private function linkKey(string $sourceType, string $sourceId): string
    {
        return "{$sourceType}\0{$sourceId}";
    }

    private function payloadIsCompatible(
        string $targetType,
        array $source,
        array $payload,
        string $operation,
    ): bool {
        foreach ($payload as $field => $value) {
            $invalidText = $this->invalidTextReason($value);
            if ($invalidText !== null) {
                $this->conflicts[] = [
                    'reason' => $invalidText,
                    'source_type' => $source['source_type'] ?? $targetType,
                    'source_id' => $source['source_id'] ?? null,
                    'target_type' => $targetType,
                    'field' => $field,
                ];

                return false;
            }
        }
        $schema = $this->writeSchema[$targetType] ?? [];
        if ($this->writeSchemaKnown && (! is_array($schema) || $schema === [])) {
            $this->conflicts[] = [
                'reason' => 'target_write_schema_unavailable',
                'source_type' => $source['source_type'] ?? $targetType,
                'source_id' => $source['source_id'] ?? null,
                'target_type' => $targetType,
                'operation' => $operation,
            ];

            return false;
        }
        if (! is_array($schema) || $schema === []) {
            return true;
        }

        if ($operation === 'create') {
            foreach ($schema as $field => $definition) {
                if (! is_array($definition)) {
                    continue;
                }
                if (($definition['required'] ?? false) === true
                    && ($definition['read_only'] ?? false) !== true
                    && ! array_key_exists((string) $field, $payload)
                ) {
                    $this->conflicts[] = [
                        'reason' => 'target_required_field_missing',
                        'source_type' => $source['source_type'] ?? $targetType,
                        'source_id' => $source['source_id'] ?? null,
                        'target_type' => $targetType,
                        'field' => $field,
                    ];

                    return false;
                }
            }
        }

        foreach ($payload as $field => $value) {
            $definition = $schema[$field] ?? null;
            if (is_array($definition) && ! $this->fieldValueIsCompatible($value, $definition)) {
                $this->conflicts[] = [
                    'reason' => 'target_field_constraint',
                    'source_type' => $source['source_type'] ?? $targetType,
                    'source_id' => $source['source_id'] ?? null,
                    'target_type' => $targetType,
                    'field' => $field,
                ];

                return false;
            }
            $choices = is_array($definition) ? ($definition['choices'] ?? []) : [];
            if (! is_array($choices) || $choices === [] || is_array($value)) {
                continue;
            }
            $allowed = array_values(array_filter(array_map(
                fn (mixed $choice): mixed => is_array($choice) ? ($choice['value'] ?? null) : null,
                $choices,
            ), fn (mixed $choice): bool => $choice !== null));
            if ($allowed !== [] && ! in_array($value, $allowed, true)) {
                $this->conflicts[] = [
                    'reason' => 'unsupported_target_choice',
                    'source_type' => $source['source_type'] ?? $targetType,
                    'source_id' => $source['source_id'] ?? null,
                    'target_type' => $targetType,
                    'field' => $field,
                    'value' => $value,
                    'allowed' => $allowed,
                ];

                return false;
            }
        }

        return true;
    }

    private function invalidTextReason(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $reason = $this->invalidTextReason($item);
                if ($reason !== null) {
                    return $reason;
                }
            }

            return null;
        }
        if (! is_string($value)) {
            return null;
        }
        if (preg_match('//u', $value) !== 1) {
            return 'target_text_encoding_invalid';
        }

        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1
            ? 'target_text_control_character'
            : null;
    }

    private function fieldValueIsCompatible(mixed $value, array $definition): bool
    {
        if ($this->containsReference($value)) {
            return true;
        }
        if (is_string($value)
            && isset($definition['max_length'])
            && is_numeric($definition['max_length'])
            && mb_strlen($value) > (int) $definition['max_length']
        ) {
            return false;
        }
        if (is_numeric($value)
            && isset($definition['min_value'])
            && is_numeric($definition['min_value'])
            && (float) $value < (float) $definition['min_value']
        ) {
            return false;
        }
        if (is_numeric($value)
            && isset($definition['max_value'])
            && is_numeric($definition['max_value'])
            && (float) $value > (float) $definition['max_value']
        ) {
            return false;
        }

        return true;
    }

    private function sourceRecords(array $objects): array
    {
        $records = [];
        foreach ($objects as $type => $items) {
            foreach (is_array($items) ? $items : [] as $object) {
                if (! is_array($object)) {
                    continue;
                }
                $records[$type][] = [
                    'source_type' => $object['source_type'] ?? rtrim($type, 's'),
                    'source_id' => $object['source_id'] ?? null,
                    'source_hash' => $object['source_hash'] ?? null,
                    'legacy' => $object['legacy'] ?? [],
                ];
            }
        }

        return $records;
    }

    private function differences(array $payload, array $target): array
    {
        $differences = [];
        foreach ($payload as $field => $desired) {
            if ($field === 'assigned_object_id' && $this->containsReference($desired)) {
                // A source reference cannot be resolved during planning, so an
                // operator-approved assignment is deliberately PATCHed together
                // with its type at apply time.
                $differences[$field] = $desired;

                continue;
            }
            if ($this->containsReference($desired)) {
                continue;
            }
            if ($field === 'custom_fields' && is_array($desired)) {
                $changed = [];
                foreach ($desired as $name => $value) {
                    $current = $target['custom_fields'][$name] ?? null;
                    if (is_array($current) && array_key_exists('value', $current)) {
                        $current = $current['value'];
                    }
                    if ($current !== $value) {
                        $changed[$name] = $value;
                    }
                }
                if ($changed !== []) {
                    $differences[$field] = $changed;
                }

                continue;
            }
            $current = $target[$field] ?? null;
            if (is_array($current) && array_key_exists('value', $current)) {
                $current = $current['value'];
            } elseif (is_array($current) && array_key_exists('id', $current)) {
                $current = $current['id'];
            }
            if ($current !== $desired) {
                $differences[$field] = $desired;
            }
        }

        return $differences;
    }

    private function detectDuplicateClaims(): void
    {
        $claims = [];
        foreach ($this->actions as $action) {
            if (($action['operation'] ?? null) === 'ignore') {
                continue;
            }

            $targetType = (string) $action['target_type'];
            $source = [
                'source_type' => $action['source_type'],
                'source_id' => $action['source_id'],
            ];
            if (($action['target_id'] ?? null) !== null) {
                $this->registerClaim(
                    $claims,
                    "target\0{$targetType}\0{$action['target_id']}",
                    $targetType,
                    'target_id',
                    $source,
                );
            }

            foreach ($this->identityClaims($action) as $kind => $identity) {
                $this->registerClaim(
                    $claims,
                    "identity\0{$targetType}\0{$kind}\0".CanonicalJson::encode($identity),
                    $targetType,
                    $kind,
                    $source,
                );
            }
        }
    }

    private function detectExistingTargetConflicts(): void
    {
        foreach ($this->actions as $action) {
            if (($action['operation'] ?? null) === 'ignore') {
                continue;
            }

            $targetType = (string) $action['target_type'];
            $targetId = isset($action['target_id']) ? (int) $action['target_id'] : null;
            foreach ($this->identityClaims($action) as $kind => $identity) {
                if ($this->containsReference($identity)) {
                    continue;
                }
                $collisions = array_values(array_filter(
                    $this->targetCollection($targetType),
                    function (array $target) use ($targetType, $targetId, $kind, $identity): bool {
                        if ($targetId !== null && (int) ($target['id'] ?? 0) === $targetId) {
                            return false;
                        }

                        $targetClaims = $this->targetIdentityClaims($targetType, $target);

                        return isset($targetClaims[$kind])
                            && CanonicalJson::encode($targetClaims[$kind]) === CanonicalJson::encode($identity);
                    },
                ));
                if ($collisions !== []) {
                    $this->conflicts[] = [
                        'reason' => 'target_identity_collision',
                        'source_type' => $action['source_type'],
                        'source_id' => $action['source_id'],
                        'target_type' => $targetType,
                        'identity_kind' => $kind,
                        'target_ids' => array_map(
                            fn (array $target): int => (int) $target['id'],
                            $collisions,
                        ),
                    ];
                }
            }
        }
    }

    private function identityClaims(array $action): array
    {
        $key = $action['natural_key'];
        $payload = $action['payload'];

        return match ($action['target_type']) {
            'vrf' => ($key['rd'] ?? null)
                ? ['rd' => ['rd' => $key['rd']]]
                : ['name_without_rd' => ['name' => mb_strtolower((string) ($key['name'] ?? ''))]],
            'vlan_group' => array_filter([
                'scope_name' => [
                    'scope_id' => $key['scope_id'] ?? null,
                    'name' => mb_strtolower((string) ($key['name'] ?? '')),
                ],
                'scope_slug' => isset($payload['slug']) && $payload['slug'] !== '' ? [
                    'scope_id' => $key['scope_id'] ?? null,
                    'slug' => (string) ($payload['slug'] ?? ''),
                ] : null,
            ]),
            'vlan' => array_filter([
                'group_vid' => ['group_id' => $key['group_id'] ?? null, 'vid' => $key['vid'] ?? null],
                'group_name' => isset($payload['name']) && $payload['name'] !== '' ? [
                    'group_id' => $key['group_id'] ?? null,
                    'name' => mb_strtolower((string) ($payload['name'] ?? '')),
                ] : null,
            ]),
            default => ['natural_key' => $key],
        };
    }

    private function targetIdentityClaims(string $targetType, array $target): array
    {
        $relatedId = fn (mixed $value): ?int => is_array($value)
            ? (isset($value['id']) ? (int) $value['id'] : null)
            : ($value === null ? null : (int) $value);

        return match ($targetType) {
            'vrf' => ($target['rd'] ?? null)
                ? ['rd' => ['rd' => $target['rd']]]
                : ['name_without_rd' => ['name' => mb_strtolower((string) ($target['name'] ?? ''))]],
            'vlan_group' => [
                'scope_name' => [
                    'scope_id' => $relatedId($target['scope'] ?? null),
                    'name' => mb_strtolower((string) ($target['name'] ?? '')),
                ],
                'scope_slug' => [
                    'scope_id' => $relatedId($target['scope'] ?? null),
                    'slug' => (string) ($target['slug'] ?? ''),
                ],
            ],
            'vlan' => [
                'group_vid' => [
                    'group_id' => $relatedId($target['group'] ?? null),
                    'vid' => $target['vid'] ?? null,
                ],
                'group_name' => [
                    'group_id' => $relatedId($target['group'] ?? null),
                    'name' => mb_strtolower((string) ($target['name'] ?? '')),
                ],
            ],
            'prefix' => [
                'natural_key' => [
                    'prefix' => $target['prefix'] ?? null,
                    'vrf_id' => $relatedId($target['vrf'] ?? null),
                ],
            ],
            'ip_address' => [
                'natural_key' => [
                    'address' => $target['address'] ?? null,
                    'vrf_id' => $relatedId($target['vrf'] ?? null),
                ],
            ],
            'custom_field' => [
                'natural_key' => ['name' => $target['name'] ?? null],
            ],
            default => [],
        };
    }

    private function registerClaim(
        array &$claims,
        string $signature,
        string $targetType,
        string $kind,
        array $source,
    ): void {
        if (! isset($claims[$signature])) {
            $claims[$signature] = $source;

            return;
        }

        $this->conflicts[] = [
            'reason' => 'duplicate_target_claim',
            'target_type' => $targetType,
            'identity_kind' => $kind,
            'sources' => [$claims[$signature], $source],
        ];
    }

    private function customFields(string $sourceType, array $object): array
    {
        $result = $this->policy->customFieldResult($sourceType, $object['legacy'] ?? []);
        foreach ($result['errors'] as $error) {
            $this->conflicts[] = [
                'reason' => 'invalid_custom_field_value',
                'source_type' => $sourceType,
                'source_id' => $object['source_id'] ?? null,
                ...$error,
            ];
        }

        return $result['data'];
    }

    private function naturalKeyIsIncomplete(array $key): bool
    {
        foreach ($key as $field => $value) {
            if ($value === null && in_array($field, ['prefix', 'address', 'vid', 'name', 'slug', 'cid', 'asn', 'mac_address'], true)) {
                return true;
            }
            if ($value === '' && in_array($field, ['prefix', 'address', 'name', 'slug', 'cid', 'mac_address'], true)) {
                return true;
            }
        }

        return $this->containsMissingReference($key);
    }

    private function containsReference(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        if (isset($value['$ref'])) {
            return true;
        }

        foreach ($value as $item) {
            if ($this->containsReference($item)) {
                return true;
            }
        }

        return false;
    }

    private function containsMissingReference(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        if (isset($value['$missing'])) {
            return true;
        }

        foreach ($value as $item) {
            if ($this->containsMissingReference($item)) {
                return true;
            }
        }

        return false;
    }

    private function sortActions(): void
    {
        $remaining = $this->actions;
        $sorted = [];
        while ($remaining !== []) {
            $ready = array_filter(
                $remaining,
                fn (array $action): bool => array_diff($action['dependencies'], array_keys($sorted)) === [],
            );
            if ($ready === []) {
                $this->conflicts[] = [
                    'reason' => 'dependency_cycle',
                    'action_keys' => array_keys($remaining),
                ];
                foreach ($remaining as $key => $action) {
                    $sorted[$key] = $action;
                }
                break;
            }

            uasort($ready, fn (array $left, array $right): int => [
                $this->typeOrder($left['target_type']),
                $left['source_id'],
            ] <=> [
                $this->typeOrder($right['target_type']),
                $right['source_id'],
            ]);
            foreach ($ready as $key => $action) {
                $sorted[$key] = $action;
                unset($remaining[$key]);
            }
        }

        $this->actions = $sorted;
    }

    private function typeOrder(string $type): int
    {
        return $this->resources->phase($type);
    }

    private function prefixLength(?string $prefix): int
    {
        if ($prefix === null || ! str_contains($prefix, '/')) {
            return PHP_INT_MAX;
        }

        return (int) substr($prefix, strrpos($prefix, '/') + 1);
    }
}
