<?php

namespace App\Domain\Migration\Planners;

use App\Domain\Migration\MappingPolicy;
use Illuminate\Support\Str;

final class IpamPlanner
{
    public function intents(array $objects, MappingPolicy $policy): array
    {
        $intents = [];
        foreach ($objects['vrfs'] ?? [] as $object) {
            $tenant = PlannerIntent::reference('tenant', $object['tenant_source_id'] ?? null);
            $intents[] = PlannerIntent::object('vrf', $object, [
                'rd' => $object['rd'] ?? null,
                'name' => $object['name'] ?? '',
            ], array_filter([
                'name' => $object['name'] ?? '',
                'rd' => $object['rd'] ?? null,
                'tenant' => $tenant,
                'description' => $object['description'] ?? '',
            ], fn (mixed $value): bool => $value !== null));
        }
        foreach ($objects['vlan_groups'] ?? [] as $object) {
            $name = (string) ($object['name'] ?? '');
            $intents[] = PlannerIntent::object('vlan_group', $object, [
                'name' => $name,
                'scope_id' => null,
            ], [
                'name' => $name,
                'slug' => Str::slug($name) ?: 'phpipam-'.$object['source_id'],
                'description' => $object['description'] ?? '',
            ]);
        }
        foreach ($objects['vlans'] ?? [] as $object) {
            $group = PlannerIntent::reference('vlan_group', $object['vlan_group_source_id'] ?? null);
            $tenant = PlannerIntent::reference('tenant', $object['tenant_source_id'] ?? null);
            $intents[] = PlannerIntent::object('vlan', $object, [
                'vid' => $object['vid'] ?? null,
                'group_id' => $group,
            ], array_filter([
                'vid' => $object['vid'] ?? null,
                'name' => ($object['name'] ?? '') ?: 'VLAN '.($object['vid'] ?? ''),
                'status' => $policy->status('vlan', $object['source_status'] ?? null),
                'group' => $group,
                'tenant' => $tenant,
                'description' => $object['description'] ?? '',
            ], fn (mixed $value): bool => $value !== null));
        }

        $prefixes = $objects['prefixes'] ?? [];
        usort($prefixes, fn (array $left, array $right): int => [
            $left['vrf_source_id'] ?? '',
            (int) substr(strrchr((string) ($left['prefix'] ?? ''), '/') ?: '/0', 1),
            $left['prefix'] ?? '',
        ] <=> [
            $right['vrf_source_id'] ?? '',
            (int) substr(strrchr((string) ($right['prefix'] ?? ''), '/') ?: '/0', 1),
            $right['prefix'] ?? '',
        ]);
        foreach ($prefixes as $object) {
            if (($object['is_folder'] ?? false) === true) {
                $intents[] = PlannerIntent::issue('prefix_folder_preserved', 'prefix', $object['source_id'] ?? null);

                continue;
            }
            $vrf = PlannerIntent::reference('vrf', $object['vrf_source_id'] ?? null);
            $vlan = PlannerIntent::reference('vlan', $object['vlan_source_id'] ?? null);
            $tenant = PlannerIntent::reference('tenant', $object['tenant_source_id'] ?? null);
            $intents[] = PlannerIntent::object('prefix', $object, [
                'prefix' => $object['prefix'] ?? null,
                'vrf_id' => $vrf,
            ], array_filter([
                'prefix' => $object['prefix'] ?? null,
                'status' => $policy->status('prefix', $object['source_status'] ?? null),
                'vrf' => $vrf,
                'vlan' => $vlan,
                'tenant' => $tenant,
                'description' => $object['description'] ?? '',
                'is_pool' => $object['is_pool'] ?? false,
                'mark_utilized' => $object['mark_utilized'] ?? false,
            ], fn (mixed $value): bool => $value !== null));
        }
        foreach ($objects['ip_addresses'] ?? [] as $object) {
            $vrf = PlannerIntent::reference('vrf', $object['vrf_source_id'] ?? null);
            $tenant = PlannerIntent::reference('tenant', $object['tenant_source_id'] ?? null);
            $interface = PlannerIntent::reference('interface', $object['interface_source_id'] ?? null);
            $payload = array_filter([
                'address' => $object['address'] ?? null,
                'status' => $policy->status('ip_address', $object['source_status'] ?? null),
                'vrf' => $vrf,
                'tenant' => $tenant,
                'dns_name' => $object['dns_name'] ?? '',
                'description' => $object['description'] ?? '',
                'assigned_object_type' => $interface === null ? null : 'dcim.interface',
                'assigned_object_id' => $interface,
            ], fn (mixed $value): bool => $value !== null);
            $intents[] = PlannerIntent::object('ip_address', $object, [
                'address' => $object['address'] ?? null,
                'vrf_id' => $vrf,
            ], $payload);
            if (($object['device_source_id'] ?? null) !== null && ($object['interface_source_id'] ?? null) === null) {
                $intents[] = PlannerIntent::issue(
                    'device_ip_without_port',
                    'ip_address',
                    $object['source_id'] ?? null,
                    ['device_source_id' => $object['device_source_id']],
                );
            }
        }

        return $intents;
    }
}
