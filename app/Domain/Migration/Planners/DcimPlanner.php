<?php

namespace App\Domain\Migration\Planners;

use App\Domain\Migration\CanonicalJson;
use App\Domain\Migration\MappingPolicy;
use Illuminate\Support\Str;

final class DcimPlanner
{
    public function intents(array $objects, MappingPolicy $policy): array
    {
        $intents = [];
        $locationSettings = $policy->relationSettings('location_classification') ?? [];
        $classifications = is_array($locationSettings['locations'] ?? null) ? $locationSettings['locations'] : [];
        $fallback = is_array($locationSettings['fallback_site'] ?? null) ? $locationSettings['fallback_site'] : null;

        $fallbackId = $fallback === null ? null : (string) ($fallback['id'] ?? 'fallback');
        $fallbackIsClassifiedSite = $fallbackId !== null
            && (($classifications[$fallbackId]['kind'] ?? null) === 'site');
        if ($fallback !== null && ! $fallbackIsClassifiedSite) {
            $fallbackSource = $this->synthetic('mapping_site', (string) ($fallback['id'] ?? 'fallback'), $fallback);
            $intents[] = PlannerIntent::object(
                'site',
                $fallbackSource,
                ['slug' => $this->slug($fallback['slug'] ?? $fallback['name'] ?? 'phpipam-fallback-site', 'phpipam-fallback-site')],
                [
                    'name' => (string) ($fallback['name'] ?? 'phpIPAM fallback'),
                    'slug' => $this->slug($fallback['slug'] ?? $fallback['name'] ?? 'phpipam-fallback-site', 'phpipam-fallback-site'),
                    'status' => 'active',
                    'description' => (string) ($fallback['description'] ?? 'Fallback selected in IpamFerry'),
                ],
                ($fallback['approved'] ?? false) === true,
            );
        }

        foreach ($objects['locations'] ?? [] as $location) {
            $sourceId = (string) ($location['source_id'] ?? '');
            $classification = is_array($classifications[$sourceId] ?? null) ? $classifications[$sourceId] : null;
            if ($classification === null || ! in_array($classification['kind'] ?? null, ['site', 'location'], true)) {
                $intents[] = PlannerIntent::issue('location_classification_required', 'location', $sourceId);

                continue;
            }
            $name = (string) ($classification['name'] ?? $location['name'] ?? '');
            $slug = $this->slug($classification['slug'] ?? $name, 'phpipam-location-'.$sourceId);
            if ($classification['kind'] === 'site') {
                $intents[] = PlannerIntent::object('site', $location, ['slug' => $slug], [
                    'name' => $name,
                    'slug' => $slug,
                    'status' => 'active',
                    'physical_address' => $location['address'] ?? '',
                    'description' => $location['description'] ?? '',
                ], ($classification['approved'] ?? false) === true);

                continue;
            }
            $siteSourceId = $classification['site_source_id'] ?? ($fallback['id'] ?? null);
            if ($siteSourceId === null) {
                $intents[] = PlannerIntent::issue('location_site_required', 'location', $sourceId);

                continue;
            }
            $site = PlannerIntent::reference('site', $siteSourceId);
            $parent = PlannerIntent::reference('location', $classification['parent_source_id'] ?? $location['parent_source_id'] ?? null);
            $intents[] = PlannerIntent::object('location', $location, [
                'site_id' => $site,
                'parent_id' => $parent,
                'slug' => $slug,
            ], array_filter([
                'site' => $site,
                'parent' => $parent,
                'name' => $name,
                'slug' => $slug,
                'status' => 'active',
                'description' => $location['description'] ?? '',
            ], fn (mixed $value): bool => $value !== null), ($classification['approved'] ?? false) === true);
        }

        foreach ($objects['racks'] ?? [] as $rack) {
            $resolved = $this->locationReferences($rack['location_source_id'] ?? null, $classifications, $fallback);
            if ($resolved === null) {
                $intents[] = PlannerIntent::issue('rack_site_required', 'rack', $rack['source_id'] ?? null);

                continue;
            }
            $intents[] = PlannerIntent::object('rack', $rack, [
                'site_id' => $resolved['site'],
                'location_id' => $resolved['location'],
                'name' => $rack['name'] ?? '',
            ], array_filter([
                'site' => $resolved['site'],
                'location' => $resolved['location'],
                'name' => $rack['name'] ?? '',
                'status' => 'active',
                'u_height' => $rack['u_height'] ?? 42,
                'description' => $rack['description'] ?? '',
            ], fn (mixed $value): bool => $value !== null));
        }

        foreach ($objects['device_roles'] ?? [] as $role) {
            $name = (string) ($role['name'] ?? '');
            $intents[] = PlannerIntent::object('device_role', $role, [
                'slug' => $this->slug($name, 'phpipam-role-'.$role['source_id']),
            ], [
                'name' => $name,
                'slug' => $this->slug($name, 'phpipam-role-'.$role['source_id']),
                'color' => '607d8b',
                'description' => $role['description'] ?? '',
            ]);
        }

        $deviceSettings = $policy->relationSettings('device_defaults') ?? [];
        $categories = is_array($deviceSettings['categories'] ?? null) ? $deviceSettings['categories'] : [];
        $overrides = is_array($deviceSettings['devices'] ?? null) ? $deviceSettings['devices'] : [];
        $plannedManufacturers = [];
        foreach ($categories as $categoryId => $category) {
            if (! is_array($category)) {
                continue;
            }
            $manufacturer = is_array($category['manufacturer'] ?? null) ? $category['manufacturer'] : null;
            $deviceType = is_array($category['device_type'] ?? null) ? $category['device_type'] : null;
            if ($this->genericHardware($category) && ($category['hardware_confirmed'] ?? false) !== true) {
                continue;
            }
            $manufacturerSourceId = null;
            if ($manufacturer !== null) {
                $slug = $this->slug($manufacturer['slug'] ?? $manufacturer['name'] ?? '', 'phpipam-manufacturer-'.$categoryId);
                $manufacturerSourceId = 'slug:'.$slug;
                if (! isset($plannedManufacturers[$manufacturerSourceId])) {
                    $source = $this->synthetic('mapping_manufacturer', $manufacturerSourceId, $manufacturer);
                    $intents[] = PlannerIntent::object('manufacturer', $source, ['slug' => $slug], [
                        'name' => (string) ($manufacturer['name'] ?? $slug),
                        'slug' => $slug,
                        'description' => (string) ($manufacturer['description'] ?? ''),
                    ], ($manufacturer['approved'] ?? false) === true);
                    $plannedManufacturers[$manufacturerSourceId] = true;
                }
            }
            if ($deviceType !== null && $manufacturer !== null) {
                $source = $this->synthetic('mapping_device_type', (string) $categoryId, $deviceType);
                $manufacturerRef = PlannerIntent::reference('manufacturer', $manufacturerSourceId);
                $slug = $this->slug($deviceType['slug'] ?? $deviceType['model'] ?? '', 'phpipam-device-type-'.$categoryId);
                $intents[] = PlannerIntent::object('device_type', $source, [
                    'manufacturer_id' => $manufacturerRef,
                    'slug' => $slug,
                ], [
                    'manufacturer' => $manufacturerRef,
                    'model' => (string) ($deviceType['model'] ?? $slug),
                    'slug' => $slug,
                    'u_height' => max(0, (int) ($deviceType['u_height'] ?? 1)),
                    'is_full_depth' => (bool) ($deviceType['is_full_depth'] ?? true),
                    'description' => (string) ($deviceType['description'] ?? ''),
                ], ($deviceType['approved'] ?? false) === true);
            }
        }

        foreach ($objects['devices'] ?? [] as $device) {
            $sourceId = (string) ($device['source_id'] ?? '');
            $sourceCategoryId = (string) ($device['category_source_id'] ?? '');
            $override = is_array($overrides[$sourceId] ?? null) ? $overrides[$sourceId] : [];
            $categoryId = (string) ($override['role_source_id'] ?? $sourceCategoryId);
            $settings = array_replace(
                is_array($categories[$categoryId] ?? null) ? $categories[$categoryId] : [],
                $override,
            );
            $resolved = $this->locationReferences($device['location_source_id'] ?? null, $classifications, $fallback);
            $missing = [];
            if ($resolved === null) {
                $missing[] = 'site';
            }
            if ($categoryId === '') {
                $missing[] = 'role';
            }
            if (! is_array($settings['manufacturer'] ?? null)) {
                $missing[] = 'manufacturer';
            }
            if (! is_array($settings['device_type'] ?? null)) {
                $missing[] = 'device_type';
            }
            if ($this->genericHardware($settings) && ($settings['hardware_confirmed'] ?? false) !== true) {
                $missing[] = 'confirmed_physical_model';
            }
            if ($missing !== []) {
                $intents[] = PlannerIntent::issue('device_prerequisites_required', 'device', $sourceId, ['missing' => $missing]);

                continue;
            }
            $manufacturerSourceId = $this->slug(
                $settings['manufacturer']['slug'] ?? $settings['manufacturer']['name'] ?? '',
                'phpipam-manufacturer-'.$categoryId,
            );
            $manufacturerSourceId = 'slug:'.$manufacturerSourceId;
            $deviceTypeSourceId = $categoryId;
            if (array_key_exists('manufacturer', $override)) {
                $manufacturerSourceId = "device:{$sourceId}";
                $manufacturer = $settings['manufacturer'];
                $source = $this->synthetic('mapping_manufacturer', $manufacturerSourceId, $manufacturer);
                $slug = $this->slug($manufacturer['slug'] ?? $manufacturer['name'] ?? '', 'phpipam-manufacturer-'.$sourceId);
                $intents[] = PlannerIntent::object('manufacturer', $source, ['slug' => $slug], [
                    'name' => (string) ($manufacturer['name'] ?? $slug),
                    'slug' => $slug,
                    'description' => (string) ($manufacturer['description'] ?? ''),
                ], ($manufacturer['approved'] ?? false) === true);
            }
            if (array_key_exists('manufacturer', $override) || array_key_exists('device_type', $override)) {
                $deviceTypeSourceId = "device:{$sourceId}";
                $deviceType = $settings['device_type'];
                $manufacturerRef = PlannerIntent::reference('manufacturer', $manufacturerSourceId);
                $source = $this->synthetic('mapping_device_type', $deviceTypeSourceId, $deviceType);
                $slug = $this->slug($deviceType['slug'] ?? $deviceType['model'] ?? '', 'phpipam-device-type-'.$sourceId);
                $intents[] = PlannerIntent::object('device_type', $source, [
                    'manufacturer_id' => $manufacturerRef,
                    'slug' => $slug,
                ], [
                    'manufacturer' => $manufacturerRef,
                    'model' => (string) ($deviceType['model'] ?? $slug),
                    'slug' => $slug,
                    'u_height' => max(0, (int) ($deviceType['u_height'] ?? 1)),
                    'is_full_depth' => (bool) ($deviceType['is_full_depth'] ?? true),
                    'description' => (string) ($deviceType['description'] ?? ''),
                ], ($deviceType['approved'] ?? false) === true);
            }
            $role = PlannerIntent::reference('device_role', $categoryId);
            $deviceType = PlannerIntent::reference('device_type', $deviceTypeSourceId);
            $rack = PlannerIntent::reference('rack', $device['rack_source_id'] ?? null);
            $intents[] = PlannerIntent::object('device', $device, [
                'site_id' => $resolved['site'],
                'name' => $device['name'] ?? '',
            ], array_filter([
                'name' => $device['name'] ?? '',
                'status' => 'active',
                'site' => $resolved['site'],
                'location' => $resolved['location'],
                'rack' => $rack,
                'position' => $rack === null ? null : ($device['rack_position'] ?? null),
                'face' => $rack === null ? null : ($device['rack_face'] ?? 'front'),
                'role' => $role,
                'device_type' => $deviceType,
                'description' => $device['description'] ?? '',
            ], fn (mixed $value): bool => $value !== null));
        }

        foreach ($objects['interfaces'] ?? [] as $interface) {
            $device = collect($objects['devices'] ?? [])->firstWhere('source_id', $interface['device_source_id'] ?? null);
            $deviceSourceId = (string) ($device['source_id'] ?? '');
            $override = is_array($overrides[$deviceSourceId] ?? null) ? $overrides[$deviceSourceId] : [];
            $categoryId = (string) ($override['role_source_id'] ?? $device['category_source_id'] ?? '');
            $settings = array_replace(
                is_array($categories[$categoryId] ?? null) ? $categories[$categoryId] : [],
                $override,
            );
            $type = $settings['interface_type'] ?? ($deviceSettings['interface_type'] ?? null);
            if (! is_string($type) || $type === '') {
                $intents[] = PlannerIntent::issue('interface_type_required', 'interface', $interface['source_id'] ?? null);

                continue;
            }
            $deviceRef = PlannerIntent::reference('device', $interface['device_source_id'] ?? null);
            $intents[] = PlannerIntent::object('interface', $interface, [
                'device_id' => $deviceRef,
                'name' => $interface['name'] ?? '',
            ], [
                'device' => $deviceRef,
                'name' => $interface['name'] ?? '',
                'type' => $type,
                'enabled' => true,
                'description' => $interface['description'] ?? '',
            ]);
        }
        foreach ($objects['mac_addresses'] ?? [] as $mac) {
            $interface = PlannerIntent::reference('interface', $mac['interface_source_id'] ?? null);
            $intents[] = PlannerIntent::object('mac_address', $mac, [
                'mac_address' => $mac['mac_address'] ?? '',
            ], [
                'mac_address' => $mac['mac_address'] ?? '',
                'assigned_object_type' => 'dcim.interface',
                'assigned_object_id' => $interface,
                'description' => $mac['description'] ?? '',
            ]);
        }

        return $intents;
    }

    private function genericHardware(array $settings): bool
    {
        $manufacturer = is_array($settings['manufacturer'] ?? null) ? $settings['manufacturer'] : [];
        $deviceType = is_array($settings['device_type'] ?? null) ? $settings['device_type'] : [];
        $values = [
            $manufacturer['name'] ?? null,
            $manufacturer['slug'] ?? null,
            $deviceType['model'] ?? null,
            $deviceType['slug'] ?? null,
        ];

        return collect($values)->contains(fn (mixed $value): bool => is_string($value) && str_contains(mb_strtolower($value), 'generic'));
    }

    public function classification(array $objects, MappingPolicy $policy): array
    {
        $settings = $policy->relationSettings('location_classification') ?? [];

        return is_array($settings['locations'] ?? null) ? $settings['locations'] : [];
    }

    private function locationReferences(mixed $locationId, array $classifications, ?array $fallback): ?array
    {
        $sourceId = $locationId === null ? null : (string) $locationId;
        $classification = $sourceId === null || ! is_array($classifications[$sourceId] ?? null)
            ? null
            : $classifications[$sourceId];
        if (($classification['kind'] ?? null) === 'site') {
            return ['site' => PlannerIntent::reference('site', $sourceId), 'location' => null];
        }
        if (($classification['kind'] ?? null) === 'location') {
            $siteId = $classification['site_source_id'] ?? ($fallback['id'] ?? null);

            return $siteId === null ? null : [
                'site' => PlannerIntent::reference('site', $siteId),
                'location' => PlannerIntent::reference('location', $sourceId),
            ];
        }
        if ($fallback !== null) {
            return [
                'site' => PlannerIntent::reference('site', (string) ($fallback['id'] ?? 'fallback')),
                'location' => null,
            ];
        }

        return null;
    }

    private function synthetic(string $type, string $id, array $data): array
    {
        return [
            'source_type' => $type,
            'source_id' => $id,
            'source_hash' => CanonicalJson::fingerprint($data),
            'legacy' => [],
        ];
    }

    private function slug(mixed $value, string $fallback): string
    {
        return Str::slug((string) $value) ?: $fallback;
    }
}
