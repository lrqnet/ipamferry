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
        $this->mapping = array_replace_recursive(self::defaults(), $mapping);
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

    public function all(): array
    {
        return $this->mapping;
    }

    public function ignores(string $sourceType): bool
    {
        $ignored = $this->mapping['ignore_types'] ?? [];

        return is_array($ignored) && in_array($sourceType, $ignored, true);
    }

    public function status(string $targetType, mixed $sourceStatus): string
    {
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
        $updates = $this->mapping['updates'] ?? [];
        $fields = is_array($updates) ? ($updates[$targetType] ?? []) : [];

        return is_array($fields) ? array_values(array_filter($fields, 'is_string')) : [];
    }

    public function customFieldData(string $sourceType, array $legacy): array
    {
        return $this->customFieldResult($sourceType, $legacy)['data'];
    }

    public function customFieldResult(string $sourceType, array $legacy): array
    {
        $data = [];
        $errors = [];
        $rules = $this->mapping['custom_fields'] ?? [];
        foreach (is_array($rules) ? $rules : [] as $index => $rule) {
            if (! is_array($rule) || ($rule['source_type'] ?? null) !== $sourceType) {
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
        $rules = $this->mapping['custom_fields'] ?? [];

        return is_array($rules) ? array_values(array_filter($rules, 'is_array')) : [];
    }

    public function validate(): array
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

    private function sourceTypes(): array
    {
        return ['vrf', 'vlan_group', 'vlan', 'prefix', 'ip_address'];
    }
}
