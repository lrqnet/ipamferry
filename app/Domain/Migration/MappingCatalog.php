<?php

namespace App\Domain\Migration;

use Stringable;

final class MappingCatalog
{
    private const EXAMPLE_LIMIT = 5;

    private const CARDINALITY_LIMIT = 100;

    private const VALUE_LIMIT = 128;

    public function build(array $source, array $target): array
    {
        return [
            'schema_version' => 2,
            'source_fingerprint' => SnapshotFingerprint::make($source),
            'target_fingerprint' => SnapshotFingerprint::make($target),
            'source' => $this->side($source['objects'] ?? [], true),
            'target' => $this->side($target['objects'] ?? [], false),
            'target_choices' => $this->choices($target['write_schema'] ?? []),
            'natural_keys' => $this->naturalKeys(),
        ];
    }

    public function current(array $catalog, array $source, array $target): bool
    {
        return ($catalog['schema_version'] ?? null) === 2
            && hash_equals((string) ($catalog['source_fingerprint'] ?? ''), SnapshotFingerprint::make($source))
            && hash_equals((string) ($catalog['target_fingerprint'] ?? ''), SnapshotFingerprint::make($target));
    }

    private function side(array $objects, bool $canonicalSourceTypes): array
    {
        $result = [];
        foreach ($objects as $type => $rows) {
            if (! is_array($rows)) {
                continue;
            }
            $safeRows = array_values(array_filter($rows, 'is_array'));
            $fields = [];
            foreach ($safeRows as $row) {
                foreach ($this->catalogFields($row) as $field => $value) {
                    $fields[$field] ??= [
                        'filled' => 0,
                        'types' => [],
                        'distinct' => [],
                        'cardinality_limited' => false,
                        'examples' => [],
                    ];
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $fields[$field]['filled']++;
                    $valueType = $this->inferType($value);
                    $fields[$field]['types'][$valueType] = true;
                    $example = $this->example($value);
                    if ($example !== null) {
                        $hash = hash('sha256', get_debug_type($value)."\0".$example);
                        if (count($fields[$field]['distinct']) < self::CARDINALITY_LIMIT) {
                            $fields[$field]['distinct'][$hash] = true;
                        } elseif (! isset($fields[$field]['distinct'][$hash])) {
                            $fields[$field]['cardinality_limited'] = true;
                        }
                        if (count($fields[$field]['examples']) < self::EXAMPLE_LIMIT
                            && ! in_array($example, $fields[$field]['examples'], true)
                        ) {
                            $fields[$field]['examples'][] = $example;
                        }
                    }
                }
            }
            ksort($fields, SORT_STRING);
            foreach ($fields as &$field) {
                $types = array_keys($field['types']);
                sort($types, SORT_STRING);
                $field = [
                    'type' => count($types) === 1 ? $types[0] : ($types === [] ? 'null' : 'mixed'),
                    'types' => $types,
                    'filled' => $field['filled'],
                    'total' => count($safeRows),
                    'fill_rate' => count($safeRows) === 0 ? 0 : round($field['filled'] / count($safeRows), 4),
                    'cardinality' => count($field['distinct']),
                    'cardinality_limited' => $field['cardinality_limited'],
                    'examples' => $field['examples'],
                ];
            }
            unset($field);
            $catalogType = $canonicalSourceTypes
                ? $this->canonicalSourceType((string) $type, $safeRows)
                : (string) $type;
            $result[$catalogType] = [
                'count' => count($safeRows),
                'fields' => $fields,
                ...($canonicalSourceTypes ? [
                    'identities' => array_map(
                        fn (array $row): array => [
                            'source_id' => mb_strimwidth((string) ($row['source_id'] ?? ''), 0, 191, '…'),
                            'label' => $this->identityLabel($row),
                            'hints' => $this->identityHints($row),
                        ],
                        array_slice($safeRows, 0, 500),
                    ),
                    'identities_truncated' => count($safeRows) > 500,
                ] : []),
            ];
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    private function catalogFields(array $row): array
    {
        $fields = [];
        foreach ($row as $key => $value) {
            $key = (string) $key;
            if ($key === 'legacy' && is_array($value)) {
                foreach ($value as $legacyKey => $legacyValue) {
                    $path = 'legacy.'.(string) $legacyKey;
                    if (! $this->sensitive($path)) {
                        $fields[$path] = $legacyValue;
                    }
                }

                continue;
            }
            if (! $this->sensitive($key) && $key !== 'source_hash') {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    private function sensitive(string $field): bool
    {
        return preg_match('/(?:^|[._-])(pass(?:word)?|token|secret|credential|community|private[_-]?key|api[_-]?key|auth)(?:$|[._-])/i', $field) === 1;
    }

    private function inferType(mixed $value): string
    {
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_float($value)) {
            return 'decimal';
        }
        if (is_array($value)) {
            return array_is_list($value) ? 'array' : 'object';
        }
        if (is_string($value)) {
            if (filter_var($value, FILTER_VALIDATE_IP)) {
                return 'ip';
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                return 'date';
            }

            return 'text';
        }

        return get_debug_type($value);
    }

    private function example(mixed $value): ?string
    {
        if (is_array($value)) {
            return null;
        }
        if (! is_scalar($value) && ! $value instanceof Stringable) {
            return null;
        }
        $example = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $value);
        if (! is_string($example) || $example === '' || $this->looksSecret($example)) {
            return null;
        }

        return mb_strimwidth($example, 0, self::VALUE_LIMIT, '…');
    }

    private function looksSecret(string $value): bool
    {
        return str_contains($value, 'PRIVATE KEY')
            || preg_match('/^(?:ghp_|github_pat_|xox[baprs]-|Bearer\s+)[A-Za-z0-9._-]{16,}$/i', $value) === 1;
    }

    private function choices(array $writeSchema): array
    {
        $result = [];
        foreach ($writeSchema as $type => $schema) {
            if (! is_array($schema)) {
                continue;
            }
            foreach ($schema as $field => $definition) {
                if (! is_array($definition) || ! is_array($definition['choices'] ?? null)) {
                    continue;
                }
                $result[(string) $type][(string) $field] = array_values(array_map(
                    fn (mixed $choice): mixed => is_array($choice)
                        ? array_intersect_key($choice, array_flip(['value', 'display_name', 'label']))
                        : $choice,
                    array_slice($definition['choices'], 0, 250),
                ));
            }
        }

        return $result;
    }

    private function identityLabel(array $row): string
    {
        foreach (['name', 'cid', 'address', 'prefix', 'mac_address', 'asn', 'description'] as $field) {
            if (isset($row[$field]) && is_scalar($row[$field]) && (string) $row[$field] !== '') {
                $label = $this->example($row[$field]);

                return $label ?? (string) ($row['source_id'] ?? '');
            }
        }

        return (string) ($row['source_id'] ?? '');
    }

    private function identityHints(array $row): array
    {
        $hints = [];
        foreach ([
            'category_source_id',
            'location_source_id',
            'rack_source_id',
            'device_source_id',
            'interface_source_id',
            'provider_source_id',
            'type_source_id',
        ] as $field) {
            $value = $row[$field] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                $hints[$field] = mb_strimwidth((string) $value, 0, 191, '…');
            }
        }

        return $hints;
    }

    private function canonicalSourceType(string $collection, array $rows): string
    {
        if (isset($rows[0]['source_type']) && is_string($rows[0]['source_type']) && $rows[0]['source_type'] !== '') {
            return $rows[0]['source_type'];
        }

        return [
            'customers' => 'customer',
            'sections' => 'section',
            'tags' => 'tag',
            'locations' => 'location',
            'racks' => 'rack',
            'device_roles' => 'device_role',
            'devices' => 'device',
            'interfaces' => 'interface',
            'mac_addresses' => 'mac_address',
            'providers' => 'provider',
            'circuit_types' => 'circuit_type',
            'circuits' => 'circuit',
            'asns' => 'asn',
            'vrfs' => 'vrf',
            'vlan_groups' => 'vlan_group',
            'vlans' => 'vlan',
            'prefixes' => 'prefix',
            'ip_addresses' => 'ip_address',
            'nat_relations' => 'nat',
        ][$collection] ?? $collection;
    }

    private function naturalKeys(): array
    {
        return [
            'tenant' => ['slug'],
            'contact' => ['name', 'email'],
            'contact_role' => ['slug'],
            'contact_assignment' => ['object_type', 'object', 'contact', 'role'],
            'site' => ['slug'],
            'location' => ['site', 'parent', 'slug'],
            'rack' => ['site', 'location', 'name'],
            'manufacturer' => ['slug'],
            'device_type' => ['manufacturer', 'slug'],
            'device_role' => ['slug'],
            'device' => ['site', 'tenant', 'name'],
            'interface' => ['device', 'name'],
            'mac_address' => ['mac_address'],
            'provider' => ['slug'],
            'circuit_type' => ['slug'],
            'circuit' => ['provider', 'cid'],
            'circuit_termination' => ['circuit', 'term_side'],
            'rir' => ['slug'],
            'asn' => ['asn'],
            'tag' => ['slug'],
            'vrf' => ['rd', 'name'],
            'vlan_group' => ['scope_type', 'scope_id', 'slug'],
            'vlan' => ['group', 'vid'],
            'prefix' => ['vrf', 'prefix'],
            'ip_address' => ['vrf', 'address'],
            'custom_field' => ['name'],
        ];
    }
}
