<?php

namespace App\Domain\Migration;

use Illuminate\Support\Str;

class MigrationPlanner
{
    private array $actions = [];

    private array $conflicts = [];

    private array $warnings = [];

    private array $sourceActions = [];

    private array $customFieldActions = [];

    private array $identityLinks = [];

    private array $writeSchema = [];

    private bool $writeSchemaKnown = false;

    private array $target;

    private MappingPolicy $policy;

    public function plan(array $source, array $target, array $mapping = [], array $identityLinks = []): array
    {
        $this->actions = [];
        $this->conflicts = [];
        $this->warnings = array_values(array_merge($source['warnings'] ?? [], $target['warnings'] ?? []));
        $this->sourceActions = [];
        $this->customFieldActions = [];
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

        $objects = $source['objects'] ?? $source;
        $this->planCustomFields();
        $this->planVrfs($objects['vrfs'] ?? []);
        $this->planVlanGroups($objects['vlan_groups'] ?? []);
        $this->planVlans($objects['vlans'] ?? []);
        $this->planPrefixes($objects['prefixes'] ?? []);
        $this->planIpAddresses($objects['ip_addresses'] ?? []);
        $this->detectExistingTargetConflicts();
        $this->detectDuplicateClaims();
        $this->sortActions();

        $preservation = [
            'unmigrated' => $source['preserved'] ?? [],
            'custom_field_definitions' => $source['custom_fields'] ?? [],
            'source_records' => $this->sourceRecords($objects),
            'ignored' => array_values(array_filter(
                $this->actions,
                fn (array $action): bool => $action['operation'] === 'ignore',
            )),
        ];

        return [
            'actions' => array_values($this->actions),
            'conflicts' => array_values($this->conflicts),
            'warnings' => array_values(array_unique($this->warnings)),
            'preservation' => $preservation,
        ];
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
            $groupReference = $this->reference('vlan_group', $object['vlan_group_source_id'] ?? null);
            $dependencies = [
                ...$this->dependencies($groupReference),
                ...$this->customFieldDependencies('vlan'),
            ];
            $naturalKey = ['vid' => $object['vid'], 'group_id' => $groupReference];
            $payload = array_filter([
                'vid' => $object['vid'],
                'name' => $object['name'] ?: 'VLAN '.$object['vid'],
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
            $operation = $matches === [] ? 'create' : 'reuse';
        }

        $existing = $matches[0] ?? null;
        $differences = $existing === null ? [] : $this->differences($payload, $existing);
        $allowedUpdates = $this->policy->allowedUpdates($targetType);
        $updatePayload = array_intersect_key($differences, array_flip($allowedUpdates));
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
            $this->addAction('custom_field', $source, ['name' => $targetName], $payload);
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

        $targetId = $this->actions[$actionKey]['target_id'] ?? null;

        return $targetId === null ? ['$ref' => $actionKey] : (int) $targetId;
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
                default => false,
            };
        }));
    }

    private function targetCollection(string $targetType): array
    {
        return match ($targetType) {
            'vrf' => $this->target['vrfs'] ?? [],
            'vlan_group' => $this->target['vlan_groups'] ?? [],
            'vlan' => $this->target['vlans'] ?? [],
            'prefix' => $this->target['prefixes'] ?? [],
            'ip_address' => $this->target['ip_addresses'] ?? [],
            'custom_field' => $this->target['custom_fields'] ?? [],
            default => [],
        };
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
        foreach (['vrfs', 'vlan_groups', 'vlans', 'prefixes', 'ip_addresses'] as $type) {
            foreach ($objects[$type] ?? [] as $object) {
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
            if ($value === null && in_array($field, ['prefix', 'address', 'vid', 'name'], true)) {
                return true;
            }
            if ($value === '' && in_array($field, ['prefix', 'address', 'name'], true)) {
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
        $position = array_search($type, ['custom_field', 'vrf', 'vlan_group', 'vlan', 'prefix', 'ip_address'], true);

        return $position === false ? PHP_INT_MAX : $position;
    }

    private function prefixLength(?string $prefix): int
    {
        if ($prefix === null || ! str_contains($prefix, '/')) {
            return PHP_INT_MAX;
        }

        return (int) substr($prefix, strrpos($prefix, '/') + 1);
    }
}
