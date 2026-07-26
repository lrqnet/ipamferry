<?php

namespace App\Domain\Migration;

use DateTimeImmutable;
use Illuminate\Support\Str;
use JsonException;

class MappingPolicy
{
    private array $mapping;

    public function __construct(array $mapping)
    {
        $this->mapping = ($mapping['schema_version'] ?? 1) === 2
            ? array_replace(self::v2Defaults(), $mapping)
            : array_replace_recursive(self::defaults(), $mapping);
    }

    public static function defaults(): array
    {
        return [
            'schema_version' => 1,
            'ignore_types' => [],
            'statuses' => [
                'prefix' => ['default' => 'active'],
                'ip_address' => ['default' => 'active'],
                'vlan' => ['default' => 'active'],
            ],
            'updates' => [
                'vrf' => [],
                'vlan_group' => [],
                'vlan' => [],
                'prefix' => [],
                'ip_address' => [],
            ],
            'custom_fields' => [],
        ];
    }

    public static function v2Defaults(): array
    {
        return [
            'schema_version' => 2,
            'object_policies' => [
                'vrf' => ['policy' => 'migrate', 'target_type' => 'vrf'],
                'vlan_group' => ['policy' => 'migrate', 'target_type' => 'vlan_group'],
                'vlan' => ['policy' => 'migrate', 'target_type' => 'vlan'],
                'prefix' => ['policy' => 'migrate', 'target_type' => 'prefix'],
                'ip_address' => ['policy' => 'migrate', 'target_type' => 'ip_address'],
            ],
            'reference_rules' => [],
            'status_rules' => [
                ['id' => 'status-ip-default', 'source_type' => 'ip_address', 'source_value' => '*', 'target_value' => 'active'],
                ['id' => 'status-prefix-default', 'source_type' => 'prefix', 'source_value' => '*', 'target_value' => 'active'],
                ['id' => 'status-vlan-default', 'source_type' => 'vlan', 'source_value' => '*', 'target_value' => 'active'],
            ],
            'update_rules' => [
                'vrf' => [],
                'vlan_group' => [],
                'vlan' => [],
                'prefix' => [],
                'ip_address' => [],
            ],
            'field_rules' => [],
            'relation_rules' => [],
            'preservation_rules' => [],
        ];
    }

    public function schemaVersion(): int
    {
        return (int) ($this->mapping['schema_version'] ?? 1);
    }

    public function upgraded(): array
    {
        if ($this->schemaVersion() === 2) {
            return $this->canonicalize($this->mapping);
        }

        $upgraded = self::v2Defaults();
        foreach ($this->sourceTypes() as $sourceType) {
            if ($this->ignores($sourceType)) {
                $upgraded['object_policies'][$sourceType] = [
                    'policy' => 'ignore',
                    'target_type' => $sourceType,
                ];
            }
        }
        $upgraded['status_rules'] = [];
        foreach (($this->mapping['statuses'] ?? []) as $sourceType => $rules) {
            if (! is_array($rules)) {
                continue;
            }
            foreach ($rules as $sourceValue => $targetValue) {
                $upgraded['status_rules'][] = [
                    'id' => $this->ruleId('status', [
                        'source_type' => $sourceType,
                        'source_value' => (string) $sourceValue,
                        'target_value' => $targetValue,
                    ]),
                    'source_type' => $sourceType,
                    'source_value' => (string) $sourceValue,
                    'target_value' => $targetValue,
                ];
            }
        }
        $upgraded['update_rules'] = array_replace($upgraded['update_rules'], $this->mapping['updates'] ?? []);
        foreach ($this->mapping['custom_fields'] ?? [] as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $upgraded['field_rules'][] = [
                'id' => $this->ruleId('field', $rule),
                'target_kind' => 'custom_field',
                ...$rule,
            ];
        }

        return $this->canonicalize($upgraded);
    }

    public function all(): array
    {
        return $this->schemaVersion() === 2
            ? $this->canonicalize($this->mapping)
            : $this->mapping;
    }

    public function ignores(string $sourceType): bool
    {
        if ($this->schemaVersion() === 2) {
            return $this->objectPolicy($sourceType) === 'ignore';
        }
        $ignored = $this->mapping['ignore_types'] ?? [];

        return is_array($ignored) && in_array($sourceType, $ignored, true);
    }

    public function objectPolicy(string $sourceType): string
    {
        $policy = $this->mapping['object_policies'][$sourceType]['policy'] ?? 'preserve';

        return in_array($policy, ['migrate', 'ignore', 'preserve'], true) ? $policy : 'preserve';
    }

    public function migrates(string $sourceType): bool
    {
        return $this->schemaVersion() === 1
            ? ! $this->ignores($sourceType)
            : $this->objectPolicy($sourceType) === 'migrate';
    }

    public function relationSettings(string $relation): ?array
    {
        foreach ($this->mapping['relation_rules'] ?? [] as $rule) {
            if (is_array($rule)
                && ($rule['relation'] ?? null) === $relation
                && ($rule['enabled'] ?? false) === true
            ) {
                return is_array($rule['settings'] ?? null) ? $rule['settings'] : [];
            }
        }

        return null;
    }

    public function preservationRules(): array
    {
        $rules = $this->mapping['preservation_rules'] ?? [];

        return is_array($rules) ? $rules : [];
    }

    public function status(string $targetType, mixed $sourceStatus): string
    {
        if ($this->schemaVersion() === 2) {
            $fallback = 'active';
            foreach ($this->mapping['status_rules'] ?? [] as $rule) {
                if (! is_array($rule) || ($rule['source_type'] ?? null) !== $targetType) {
                    continue;
                }
                if (($rule['source_value'] ?? '*') === '*') {
                    $fallback = (string) ($rule['target_value'] ?? $fallback);
                }
                if ((string) ($rule['source_value'] ?? '') === (string) $sourceStatus) {
                    return (string) $rule['target_value'];
                }
            }

            return $fallback;
        }
        $statuses = $this->mapping['statuses'] ?? [];
        $mapping = is_array($statuses) ? ($statuses[$targetType] ?? ['default' => 'active']) : ['default' => 'active'];
        if (! is_array($mapping)) {
            return 'active';
        }
        $key = $sourceStatus === null ? 'default' : (string) $sourceStatus;

        $status = $mapping[$key] ?? $mapping['default'] ?? 'active';

        return is_string($status) && $status !== '' ? $status : 'active';
    }

    public function allowedUpdates(string $targetType): array
    {
        $updates = $this->mapping[$this->schemaVersion() === 2 ? 'update_rules' : 'updates'] ?? [];
        $fields = is_array($updates) ? ($updates[$targetType] ?? []) : [];

        return is_array($fields) ? array_values(array_filter($fields, 'is_string')) : [];
    }

    public function customFieldData(string $sourceType, array $legacy): array
    {
        return $this->customFieldResult($sourceType, $legacy)['data'];
    }

    public function customFieldResult(string $sourceType, array $legacy): array
    {
        return $this->transformationResult($sourceType, $legacy, true);
    }

    public function fieldResult(string $sourceType, array $source): array
    {
        return $this->transformationResult($sourceType, $source, false);
    }

    private function transformationResult(string $sourceType, array $legacy, bool $customFields): array
    {
        $data = [];
        $errors = [];
        $rules = $this->mapping[$this->schemaVersion() === 2 ? 'field_rules' : 'custom_fields'] ?? [];
        foreach (is_array($rules) ? $rules : [] as $index => $rule) {
            if (! is_array($rule) || ($rule['source_type'] ?? null) !== $sourceType) {
                continue;
            }
            if ($this->schemaVersion() === 2
                && (($rule['target_kind'] ?? 'field') === 'custom_field') !== $customFields
            ) {
                continue;
            }

            $target = $rule['target'] ?? null;
            $action = $rule['action'] ?? 'ignore';
            if (! is_string($target) || $target === '' || $action === 'ignore') {
                continue;
            }

            $value = match ($action) {
                'copy' => $legacy[$rule['source_field'] ?? ''] ?? null,
                'fixed' => $rule['value'] ?? null,
                'concat' => $this->concatenate($rule, $legacy),
                'normalize' => $this->normalizeValue($legacy[$rule['source_field'] ?? ''] ?? null, $rule['mode'] ?? 'trim'),
                'lookup' => ($rule['table'] ?? [])[(string) ($legacy[$rule['source_field'] ?? ''] ?? '')] ?? ($rule['default'] ?? null),
                default => null,
            };

            if ($value === null || $value === '') {
                continue;
            }

            [$valid, $converted] = $this->convertValue($value, (string) ($rule['data_type'] ?? 'text'));
            if (! $valid) {
                $errors[] = [
                    'rule' => $index,
                    'target' => $target,
                    'data_type' => $rule['data_type'] ?? 'text',
                ];

                continue;
            }
            $data[$target] = $converted;
        }

        return ['data' => $data, 'errors' => $errors];
    }

    public function customFieldRules(): array
    {
        $rules = $this->mapping[$this->schemaVersion() === 2 ? 'field_rules' : 'custom_fields'] ?? [];

        return is_array($rules) ? array_values(array_filter(
            $rules,
            fn (mixed $rule): bool => is_array($rule)
                && ($this->schemaVersion() === 1 || ($rule['target_kind'] ?? 'field') === 'custom_field'),
        )) : [];
    }

    public function validate(): array
    {
        if ($this->schemaVersion() === 2) {
            return array_column($this->validationIssues(), 'message');
        }

        return $this->validateV1();
    }

    public function validationIssues(): array
    {
        if ($this->schemaVersion() !== 2) {
            return array_map(
                fn (string $message): array => [
                    'code' => 'mapping.invalid_v1',
                    'pointer' => '',
                    'message' => $message,
                ],
                $this->validateV1(),
            );
        }

        return $this->validateV2();
    }

    private function validateV1(): array
    {
        $errors = [];
        $allowedTopLevel = ['schema_version', 'ignore_types', 'statuses', 'updates', 'custom_fields'];
        foreach (array_keys($this->mapping) as $key) {
            if (! in_array($key, $allowedTopLevel, true)) {
                $errors[] = "Unsupported mapping property {$key}.";
            }
        }
        if (($this->mapping['schema_version'] ?? null) !== 1) {
            $errors[] = 'Unsupported mapping schema version.';
        }

        if (! is_array($this->mapping['ignore_types'] ?? null)) {
            $errors[] = 'ignore_types must be an array.';
        } else {
            foreach ($this->mapping['ignore_types'] as $type) {
                if (! is_string($type) || ! in_array($type, $this->sourceTypes(), true)) {
                    $errors[] = 'ignore_types contains an unsupported source type.';
                }
            }
        }
        if (! is_array($this->mapping['statuses'] ?? null)) {
            $errors[] = 'statuses must be an object.';
        } else {
            foreach ($this->mapping['statuses'] as $type => $statuses) {
                if (! in_array($type, ['prefix', 'ip_address', 'vlan'], true) || ! is_array($statuses)) {
                    $errors[] = "Status mapping {$type} is invalid.";

                    continue;
                }
                foreach ($statuses as $target) {
                    if (! is_string($target) || $target === '') {
                        $errors[] = "Status mapping {$type} must contain non-empty target values.";
                    }
                }
            }
        }
        if (! is_array($this->mapping['updates'] ?? null)) {
            $errors[] = 'updates must be an object.';
        } else {
            $updateFields = [
                'vrf' => ['name', 'rd', 'description', 'custom_fields'],
                'vlan_group' => ['name', 'slug', 'description', 'custom_fields'],
                'vlan' => ['name', 'status', 'description', 'custom_fields'],
                'prefix' => ['status', 'description', 'is_pool', 'mark_utilized', 'custom_fields'],
                'ip_address' => ['status', 'dns_name', 'description', 'custom_fields'],
            ];
            foreach ($this->mapping['updates'] as $type => $fields) {
                if (! isset($updateFields[$type]) || ! is_array($fields)) {
                    $errors[] = "Update mapping {$type} is invalid.";

                    continue;
                }
                foreach ($fields as $field) {
                    if (! is_string($field) || ! in_array($field, $updateFields[$type], true)) {
                        $errors[] = "Update mapping {$type} contains unsupported field.";
                    }
                }
            }
        }
        if (! is_array($this->mapping['custom_fields'] ?? null)) {
            $errors[] = 'custom_fields must be an array.';

            return $errors;
        }

        $allowedActions = ['ignore', 'copy', 'fixed', 'concat', 'normalize', 'lookup'];
        $seenTargets = [];
        foreach ($this->mapping['custom_fields'] as $index => $rule) {
            if (! is_array($rule) || ! in_array($rule['action'] ?? null, $allowedActions, true)) {
                $errors[] = "Custom-field rule {$index} has an unsupported action.";

                continue;
            }
            $action = $rule['action'];
            $allowedProperties = [
                'action',
                'source_type',
                'source_field',
                'target',
                'value',
                'fields',
                'separator',
                'mode',
                'table',
                'default',
                'data_type',
                'label',
                'description',
            ];
            foreach (array_keys($rule) as $property) {
                if (! in_array($property, $allowedProperties, true)) {
                    $errors[] = "Custom-field rule {$index} has an unsupported property {$property}.";
                }
            }
            if (! isset($rule['source_type'])
                || ! is_string($rule['source_type'])
                || ! in_array($rule['source_type'], $this->sourceTypes(), true)
            ) {
                $errors[] = "Custom-field rule {$index} has an unsupported source_type.";
            }
            if ($action !== 'ignore' && (! isset($rule['target']) || ! is_string($rule['target']) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $rule['target']))) {
                $errors[] = "Custom-field rule {$index} has an invalid target.";
            }
            if (isset($rule['data_type']) && ! in_array($rule['data_type'], [
                'text',
                'longtext',
                'integer',
                'boolean',
                'date',
                'url',
                'json',
                'decimal',
            ], true)) {
                $errors[] = "Custom-field rule {$index} has an unsupported data_type.";
            }
            if (in_array($action, ['copy', 'normalize', 'lookup'], true)
                && (! isset($rule['source_field']) || ! is_string($rule['source_field']) || $rule['source_field'] === '')
            ) {
                $errors[] = "Custom-field rule {$index} requires source_field.";
            }
            if ($action === 'fixed' && ! array_key_exists('value', $rule)) {
                $errors[] = "Custom-field rule {$index} requires value.";
            }
            if ($action === 'concat'
                && (
                    ! isset($rule['fields'])
                    || ! is_array($rule['fields'])
                    || $rule['fields'] === []
                    || array_values(array_filter($rule['fields'], 'is_string')) !== array_values($rule['fields'])
                )
            ) {
                $errors[] = "Custom-field rule {$index} requires a string fields array.";
            }
            if ($action === 'normalize'
                && ! in_array($rule['mode'] ?? 'trim', ['trim', 'lower', 'upper', 'slug'], true)
            ) {
                $errors[] = "Custom-field rule {$index} has an unsupported normalization mode.";
            }
            if ($action === 'lookup' && ! is_array($rule['table'] ?? null)) {
                $errors[] = "Custom-field rule {$index} requires a lookup table.";
            }
            if (isset($rule['separator']) && (! is_string($rule['separator']) || mb_strlen($rule['separator']) > 64)) {
                $errors[] = "Custom-field rule {$index} has an invalid separator.";
            }

            if ($action !== 'ignore'
                && is_string($rule['source_type'] ?? null)
                && is_string($rule['target'] ?? null)
            ) {
                $identity = "{$rule['source_type']}\0{$rule['target']}";
                if (isset($seenTargets[$identity])) {
                    $errors[] = "Custom-field rule {$index} duplicates a source_type and target pair.";
                }
                $seenTargets[$identity] = true;
            }
        }

        return $errors;
    }

    private function validateV2(): array
    {
        $issues = [];
        $allowedTopLevel = [
            'schema_version',
            'object_policies',
            'reference_rules',
            'status_rules',
            'update_rules',
            'field_rules',
            'relation_rules',
            'preservation_rules',
        ];
        foreach (array_keys($this->mapping) as $key) {
            if (! in_array($key, $allowedTopLevel, true)) {
                $issues[] = $this->issue('mapping.unsupported_property', "/{$key}", "Unsupported mapping property {$key}.");
            }
        }
        foreach (['object_policies', 'reference_rules', 'status_rules', 'update_rules', 'field_rules', 'relation_rules', 'preservation_rules'] as $key) {
            if (! is_array($this->mapping[$key] ?? null)) {
                $issues[] = $this->issue('mapping.invalid_type', "/{$key}", "{$key} must be an object or array.");
            }
        }
        foreach (($this->mapping['object_policies'] ?? []) as $type => $policy) {
            $pointer = '/object_policies/'.$this->pointer((string) $type);
            if (! in_array($type, $this->sourceTypes(), true) || ! is_array($policy)) {
                $issues[] = $this->issue('mapping.invalid_object_policy', $pointer, "Object policy {$type} is invalid.");

                continue;
            }
            if (! in_array($policy['policy'] ?? null, ['migrate', 'ignore', 'preserve'], true)) {
                $issues[] = $this->issue('mapping.invalid_policy', "{$pointer}/policy", 'Policy must be migrate, ignore or preserve.');
            }
            if (($policy['policy'] ?? null) === 'migrate' && (! is_string($policy['target_type'] ?? null) || $policy['target_type'] === '')) {
                $issues[] = $this->issue('mapping.target_required', "{$pointer}/target_type", 'Migrated objects require a target_type.');
            } elseif (($policy['policy'] ?? null) === 'migrate'
                && ! in_array($policy['target_type'], array_keys((new ResourceRegistry)->all()), true)
            ) {
                $issues[] = $this->issue('mapping.invalid_target_type', "{$pointer}/target_type", 'Unsupported NetBox target type.');
            }
        }

        $this->validateRuleList($issues, 'reference_rules', ['id', 'source_type', 'source_field', 'target_type', 'target_field', 'match'], ['id', 'source_type', 'target_type']);
        $this->validateRuleList($issues, 'status_rules', ['id', 'source_type', 'source_value', 'target_value'], ['id', 'source_type', 'source_value', 'target_value']);
        $this->validateRuleList($issues, 'relation_rules', ['id', 'relation', 'source_type', 'target_type', 'enabled', 'settings'], ['id', 'relation']);
        foreach (($this->mapping['reference_rules'] ?? []) as $index => $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $pointer = "/reference_rules/{$index}";
            if (($rule['match'] ?? 'natural_key') !== 'natural_key') {
                $issues[] = $this->issue('mapping.reference_natural_key_required', "{$pointer}/match", 'References must match by natural key.');
            }
            if (! in_array($rule['source_type'] ?? null, $this->sourceTypes(), true)) {
                $issues[] = $this->issue('mapping.invalid_source_type', "{$pointer}/source_type", 'Unsupported source type.');
            }
            if (! in_array($rule['target_type'] ?? null, array_keys((new ResourceRegistry)->all()), true)) {
                $issues[] = $this->issue('mapping.invalid_reference_target', "{$pointer}/target_type", 'Unsupported reference target type.');
            }
            if (preg_match('/(?:^|_)id$/', (string) ($rule['target_field'] ?? '')) === 1) {
                $issues[] = $this->issue('mapping.reference_numeric_id_forbidden', "{$pointer}/target_field", 'NetBox numeric IDs cannot be stored in mapping rules.');
            }
        }

        $allowedActions = ['ignore', 'copy', 'fixed', 'concat', 'normalize', 'lookup'];
        $seenTargets = [];
        foreach (($this->mapping['field_rules'] ?? []) as $index => $rule) {
            $pointer = "/field_rules/{$index}";
            if (! is_array($rule)) {
                $issues[] = $this->issue('mapping.invalid_rule', $pointer, 'Field rule must be an object.');

                continue;
            }
            $allowed = ['id', 'action', 'source_type', 'source_field', 'target', 'target_kind', 'value', 'fields', 'separator', 'mode', 'table', 'default', 'data_type', 'label', 'description'];
            foreach (array_keys($rule) as $property) {
                if (! in_array($property, $allowed, true)) {
                    $issues[] = $this->issue('mapping.unsupported_rule_property', "{$pointer}/".$this->pointer((string) $property), "Unsupported field-rule property {$property}.");
                }
            }
            if (! $this->validRuleId($rule['id'] ?? null)) {
                $issues[] = $this->issue('mapping.invalid_rule_id', "{$pointer}/id", 'Rule id must be stable and URL-safe.');
            }
            if (! in_array($rule['action'] ?? null, $allowedActions, true)) {
                $issues[] = $this->issue('mapping.invalid_action', "{$pointer}/action", 'Unsupported field action.');
            }
            if (! in_array($rule['target_kind'] ?? 'field', ['field', 'custom_field'], true)) {
                $issues[] = $this->issue('mapping.invalid_target_kind', "{$pointer}/target_kind", 'Target kind must be field or custom_field.');
            }
            if (! in_array($rule['source_type'] ?? null, $this->sourceTypes(), true)) {
                $issues[] = $this->issue('mapping.invalid_source_type', "{$pointer}/source_type", 'Unsupported source type.');
            }
            if (($rule['action'] ?? null) !== 'ignore' && ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string) ($rule['target'] ?? ''))) {
                $issues[] = $this->issue('mapping.invalid_target', "{$pointer}/target", 'Target must be a canonical field name.');
            }
            if (in_array($rule['action'] ?? null, ['copy', 'normalize', 'lookup'], true) && ! is_string($rule['source_field'] ?? null)) {
                $issues[] = $this->issue('mapping.source_field_required', "{$pointer}/source_field", 'This action requires source_field.');
            }
            if (($rule['action'] ?? null) === 'concat' && (! is_array($rule['fields'] ?? null) || $rule['fields'] === [])) {
                $issues[] = $this->issue('mapping.fields_required', "{$pointer}/fields", 'Concat requires source fields.');
            }
            if (($rule['action'] ?? null) === 'lookup' && ! is_array($rule['table'] ?? null)) {
                $issues[] = $this->issue('mapping.lookup_required', "{$pointer}/table", 'Lookup requires a conversion table.');
            }
            $identity = (string) ($rule['source_type'] ?? '')."\0".(string) ($rule['target'] ?? '');
            if (($rule['action'] ?? null) !== 'ignore' && isset($seenTargets[$identity])) {
                $issues[] = $this->issue('mapping.duplicate_target', "{$pointer}/target", 'A source type may write each target field only once.');
            }
            $seenTargets[$identity] = true;
        }

        $updateFields = [
            'tenant' => ['name', 'slug', 'description', 'comments', 'tags', 'custom_fields'],
            'site' => ['name', 'slug', 'status', 'description', 'physical_address', 'comments', 'tags', 'custom_fields'],
            'location' => ['name', 'slug', 'status', 'description', 'tags', 'custom_fields'],
            'rack' => ['name', 'status', 'serial', 'asset_tag', 'type', 'width', 'u_height', 'description', 'comments', 'tags', 'custom_fields'],
            'manufacturer' => ['name', 'slug', 'description'],
            'device_type' => ['model', 'slug', 'part_number', 'u_height', 'is_full_depth', 'description', 'comments', 'tags', 'custom_fields'],
            'device_role' => ['name', 'slug', 'color', 'description'],
            'device' => ['name', 'status', 'serial', 'asset_tag', 'description', 'comments', 'tags', 'custom_fields'],
            'interface' => ['name', 'type', 'enabled', 'description', 'mtu', 'mac_address', 'tags', 'custom_fields'],
            'provider' => ['name', 'slug', 'description', 'comments', 'tags', 'custom_fields'],
            'circuit_type' => ['name', 'slug', 'description'],
            'circuit' => ['cid', 'status', 'install_date', 'termination_date', 'commit_rate', 'description', 'comments', 'tags', 'custom_fields'],
            'rir' => ['name', 'slug', 'is_private', 'description'],
            'asn' => ['asn', 'description', 'comments', 'tags', 'custom_fields'],
            'tag' => ['name', 'slug', 'color', 'description'],
            'vrf' => ['name', 'rd', 'description', 'comments', 'tags', 'custom_fields'],
            'vlan_group' => ['name', 'slug', 'description', 'tags', 'custom_fields'],
            'vlan' => ['name', 'status', 'description', 'comments', 'tags', 'custom_fields'],
            'prefix' => ['status', 'description', 'comments', 'is_pool', 'mark_utilized', 'tags', 'custom_fields'],
            'ip_address' => ['status', 'dns_name', 'description', 'comments', 'tags', 'custom_fields'],
        ];
        foreach (($this->mapping['update_rules'] ?? []) as $type => $fields) {
            if (! isset($updateFields[$type]) || ! is_array($fields)) {
                $issues[] = $this->issue('mapping.invalid_update_rule', '/update_rules/'.$this->pointer((string) $type), "Update rule {$type} is invalid.");

                continue;
            }
            foreach ($fields as $index => $field) {
                if (! is_string($field) || ! in_array($field, $updateFields[$type], true)) {
                    $issues[] = $this->issue('mapping.invalid_update_field', '/update_rules/'.$this->pointer((string) $type)."/{$index}", 'Unsupported opt-in update field.');
                }
            }
        }

        foreach (($this->mapping['preservation_rules'] ?? []) as $type => $handling) {
            if (! in_array($type, $this->sourceTypes(), true) || ! in_array($handling, ['report', 'note', 'custom_field', 'discard'], true)) {
                $issues[] = $this->issue('mapping.invalid_preservation', '/preservation_rules/'.$this->pointer((string) $type), 'Unsupported preservation rule.');
            }
        }

        $ids = [];
        foreach (['reference_rules', 'status_rules', 'field_rules', 'relation_rules'] as $section) {
            foreach (($this->mapping[$section] ?? []) as $index => $rule) {
                $id = is_array($rule) ? ($rule['id'] ?? null) : null;
                if (is_string($id) && isset($ids[$id])) {
                    $issues[] = $this->issue('mapping.duplicate_rule_id', "/{$section}/{$index}/id", 'Rule ids must be unique across the mapping.');
                }
                if (is_string($id)) {
                    $ids[$id] = true;
                }
            }
        }

        return $issues;
    }

    private function concatenate(array $rule, array $legacy): string
    {
        $values = array_map(
            fn (string $field): string => trim((string) ($legacy[$field] ?? '')),
            array_values(array_filter($rule['fields'] ?? [], 'is_string')),
        );

        return implode((string) ($rule['separator'] ?? ' '), array_filter($values, fn (string $value): bool => $value !== ''));
    }

    private function normalizeValue(mixed $value, string $mode): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match ($mode) {
            'lower' => mb_strtolower(trim($value)),
            'upper' => mb_strtoupper(trim($value)),
            'slug' => Str::slug($value),
            default => trim($value),
        };
    }

    private function convertValue(mixed $value, string $dataType): array
    {
        return match ($dataType) {
            'integer' => $this->integerValue($value),
            'boolean' => $this->booleanValue($value),
            'decimal' => is_numeric($value) ? [true, (string) $value] : [false, null],
            'json' => $this->jsonValue($value),
            'date' => $this->dateValue($value),
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false
                ? [true, $value]
                : [false, null],
            'text', 'longtext' => is_scalar($value) || $value instanceof \Stringable
                ? [true, (string) $value]
                : [false, null],
            default => [false, null],
        };
    }

    private function integerValue(mixed $value): array
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false ? [false, null] : [true, $integer];
    }

    private function booleanValue(mixed $value): array
    {
        if (is_bool($value)) {
            return [true, $value];
        }
        $normalized = is_string($value) ? mb_strtolower(trim($value)) : $value;
        if (in_array($normalized, [1, '1', 'true', 'yes', 'on'], true)) {
            return [true, true];
        }
        if (in_array($normalized, [0, '0', 'false', 'no', 'off'], true)) {
            return [true, false];
        }

        return [false, null];
    }

    private function jsonValue(mixed $value): array
    {
        if (is_array($value)) {
            return [true, $value];
        }
        if (! is_string($value)) {
            return [false, null];
        }

        try {
            return [true, json_decode($value, true, 128, JSON_THROW_ON_ERROR)];
        } catch (JsonException) {
            return [false, null];
        }
    }

    private function dateValue(mixed $value): array
    {
        if (! is_string($value)) {
            return [false, null];
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value
            ? [true, $value]
            : [false, null];
    }

    private function validateRuleList(array &$issues, string $section, array $allowed, array $required): void
    {
        foreach (($this->mapping[$section] ?? []) as $index => $rule) {
            $pointer = "/{$section}/{$index}";
            if (! is_array($rule)) {
                $issues[] = $this->issue('mapping.invalid_rule', $pointer, 'Rule must be an object.');

                continue;
            }
            foreach (array_keys($rule) as $property) {
                if (! in_array($property, $allowed, true)) {
                    $issues[] = $this->issue('mapping.unsupported_rule_property', "{$pointer}/".$this->pointer((string) $property), "Unsupported rule property {$property}.");
                }
            }
            foreach ($required as $property) {
                if (! array_key_exists($property, $rule) || $rule[$property] === '') {
                    $issues[] = $this->issue('mapping.required_property', "{$pointer}/{$property}", "Rule property {$property} is required.");
                }
            }
            if (! $this->validRuleId($rule['id'] ?? null)) {
                $issues[] = $this->issue('mapping.invalid_rule_id', "{$pointer}/id", 'Rule id must be stable and URL-safe.');
            }
        }
    }

    private function issue(string $code, string $pointer, string $message): array
    {
        return compact('code', 'pointer', 'message');
    }

    private function validRuleId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[a-z0-9][a-z0-9._:-]{0,127}$/', $id) === 1;
    }

    private function pointer(string $value): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $value);
    }

    private function ruleId(string $kind, array $rule, int|string|null $salt = null): string
    {
        unset($rule['id']);

        return $kind.'-'.substr(CanonicalJson::fingerprint([$rule, $salt]), 0, 16);
    }

    private function canonicalize(array $mapping): array
    {
        foreach (['reference_rules', 'status_rules', 'field_rules', 'relation_rules'] as $section) {
            $rules = array_values(array_filter($mapping[$section] ?? [], 'is_array'));
            foreach ($rules as &$rule) {
                if (! $this->validRuleId($rule['id'] ?? null)) {
                    $rule['id'] = $this->ruleId(rtrim($section, 's'), $rule);
                }
            }
            unset($rule);
            usort($rules, fn (array $left, array $right): int => (string) $left['id'] <=> (string) $right['id']);
            $mapping[$section] = $rules;
        }
        foreach (['object_policies', 'update_rules', 'preservation_rules'] as $section) {
            if (is_array($mapping[$section] ?? null)) {
                ksort($mapping[$section], SORT_STRING);
            }
        }

        return $mapping;
    }

    private function sourceTypes(): array
    {
        return [
            'customer',
            'contact',
            'section',
            'tag',
            'location',
            'rack',
            'device_role',
            'manufacturer',
            'device_type',
            'device',
            'interface',
            'mac_address',
            'provider',
            'circuit_type',
            'circuit',
            'circuit_termination',
            'rir',
            'asn',
            'bgp_session',
            'vrf',
            'vlan_group',
            'vlan',
            'prefix',
            'ip_address',
            'ip_assignment',
            'primary_ip',
            'nat',
            'nameserver',
            'firewall',
            'scan_agent',
            'pstn',
            'logical_circuit',
            'extension',
        ];
    }
}
