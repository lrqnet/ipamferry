<?php

namespace App\Domain\Migration;

use Illuminate\Support\Str;

final class MappingSuggestions
{
    public function make(array $catalog, array $mapping): array
    {
        $suggestions = [];
        $targetTypes = array_keys($catalog['target'] ?? []);
        foreach (($catalog['source'] ?? []) as $sourceType => $sourceDefinition) {
            $targetType = $this->targetType((string) $sourceType, $targetTypes);
            if ($targetType !== null) {
                $sourceFields = array_keys($sourceDefinition['fields'] ?? []);
                $naturalKeys = $catalog['natural_keys'][$targetType] ?? [];
                $signals = $this->signals($sourceFields, is_array($naturalKeys) ? $naturalKeys : []);
                $rule = [
                    'source_type' => (string) $sourceType,
                    'target_type' => $targetType,
                    'policy' => 'migrate',
                ];
                $suggestions[] = $this->suggestion(
                    'object',
                    $rule,
                    count($signals) > 1 ? 1.0 : 0.9,
                    in_array('natural_key', $signals, true) ? 'matching_type_and_natural_key' : 'matching_object_type',
                    $signals,
                );
            }
            if ($targetType === null) {
                continue;
            }
            $targetCatalogType = isset($catalog['target'][$targetType])
                ? $targetType
                : Str::plural($targetType);
            $targetFields = array_keys($catalog['target'][$targetCatalogType]['fields'] ?? []);
            foreach (array_keys($sourceDefinition['fields'] ?? []) as $sourceField) {
                $targetField = $this->matchingField((string) $sourceField, $targetFields);
                if ($targetField === null || str_starts_with((string) $sourceField, 'legacy.')) {
                    continue;
                }
                $rule = [
                    'id' => 'field-'.substr(CanonicalJson::fingerprint([$sourceType, $sourceField, $targetType, $targetField]), 0, 16),
                    'source_type' => (string) $sourceType,
                    'source_field' => (string) $sourceField,
                    'target' => $targetField,
                    'target_kind' => 'field',
                    'action' => 'copy',
                ];
                $suggestions[] = $this->suggestion('field', $rule, 0.95, 'matching_field_name', ['name']);
            }
            if (isset($sourceDefinition['fields']['name'])
                && in_array('slug', $catalog['natural_keys'][$targetType] ?? [], true)
            ) {
                $rule = [
                    'id' => 'field-'.substr(CanonicalJson::fingerprint([$sourceType, 'name', $targetType, 'slug']), 0, 16),
                    'source_type' => (string) $sourceType,
                    'source_field' => 'name',
                    'target' => 'slug',
                    'target_kind' => 'field',
                    'action' => 'normalize',
                    'mode' => 'slug',
                ];
                $suggestions[] = $this->suggestion('field', $rule, 0.98, 'matching_slug', ['name', 'slug', 'natural_key']);
            }
        }

        usort($suggestions, fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return array_values(array_filter(
            $suggestions,
            fn (array $suggestion): bool => ! $this->alreadyMapped($suggestion, $mapping),
        ));
    }

    private function targetType(string $sourceType, array $targetTypes): ?string
    {
        $aliases = [
            'customer' => ['tenant', 'tenants'],
            'section' => ['tag', 'tags'],
            'tag' => ['tag', 'tags'],
            'location' => ['location', 'locations'],
            'rack' => ['rack', 'racks'],
            'device_role' => ['device_role', 'device_roles'],
            'device' => ['device', 'devices'],
            'interface' => ['interface', 'interfaces'],
            'mac_address' => ['mac_address', 'mac_addresses'],
            'provider' => ['provider', 'providers'],
            'circuit_type' => ['circuit_type', 'circuit_types'],
            'circuit' => ['circuit', 'circuits'],
            'asn' => ['asn', 'asns'],
            'vrf' => ['vrf', 'vrfs'],
            'vlan_group' => ['vlan_group', 'vlan_groups'],
            'vlan' => ['vlan', 'vlans'],
            'prefix' => ['prefix', 'prefixes'],
            'ip_address' => ['ip_address', 'ip_addresses'],
        ];
        [$canonical, $collection] = $aliases[$sourceType] ?? [$sourceType, Str::plural($sourceType)];

        return in_array($collection, $targetTypes, true) || in_array($canonical, $targetTypes, true)
            ? $canonical
            : null;
    }

    private function matchingField(string $sourceField, array $targetFields): ?string
    {
        $normalized = $this->normalize($sourceField);
        foreach ($targetFields as $targetField) {
            if ($this->normalize((string) $targetField) === $normalized) {
                return (string) $targetField;
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        return str_replace(['id', '_', '-', '.'], '', mb_strtolower($value));
    }

    private function signals(array $sourceFields, array $naturalKeys): array
    {
        $signals = ['type'];
        if (in_array('name', $sourceFields, true)) {
            $signals[] = 'name';
        }
        if (in_array('slug', $sourceFields, true)) {
            $signals[] = 'slug';
        }
        foreach ($naturalKeys as $naturalKey) {
            $normalized = $this->normalize((string) $naturalKey);
            if (array_filter(
                $sourceFields,
                fn (mixed $sourceField): bool => $this->normalize((string) $sourceField) === $normalized
                    || ($naturalKey === 'slug' && $sourceField === 'name'),
            ) !== []) {
                $signals[] = 'natural_key';
                break;
            }
        }

        return array_values(array_unique($signals));
    }

    private function suggestion(string $kind, array $rule, float $confidence, string $reason, array $signals): array
    {
        return [
            'id' => 'suggestion-'.substr(CanonicalJson::fingerprint([$kind, $rule]), 0, 20),
            'kind' => $kind,
            'confidence' => $confidence,
            'reason' => $reason,
            'signals' => $signals,
            'rule' => $rule,
        ];
    }

    private function alreadyMapped(array $suggestion, array $mapping): bool
    {
        $rule = $suggestion['rule'];
        if ($suggestion['kind'] === 'object') {
            return isset($mapping['object_policies'][$rule['source_type']]);
        }
        foreach ($mapping['field_rules'] ?? [] as $existing) {
            if (is_array($existing)
                && ($existing['source_type'] ?? null) === $rule['source_type']
                && ($existing['source_field'] ?? null) === $rule['source_field']
                && ($existing['target'] ?? null) === $rule['target']
            ) {
                return true;
            }
        }

        return false;
    }
}
