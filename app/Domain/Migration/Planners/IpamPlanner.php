<?php

namespace App\Domain\Migration\Planners;

use App\Domain\Migration\MappingPolicy;
use Illuminate\Support\Str;

final class IpamPlanner
{
    public function intents(array $objects, MappingPolicy $policy): array
    {
        $intents = [];
        $seenVrfRds = [];
        $seenVrfNamesWithoutRd = [];
        foreach ($objects['vrfs'] ?? [] as $object) {
            $rd = is_string($object['rd'] ?? null) ? trim($object['rd']) : '';
            $name = (string) ($object['name'] ?? '');
            $identity = $rd !== '' ? $rd : mb_strtolower($name);
            $seen = $rd !== '' ? $seenVrfRds : $seenVrfNamesWithoutRd;
            if (isset($seen[$identity])) {
                $intents[] = PlannerIntent::issue(
                    $rd !== '' ? 'duplicate_vrf_rd' : 'duplicate_vrf_name_without_rd',
                    'vrf',
                    $object['source_id'] ?? null,
                    ['identity' => $identity, 'first_source_id' => $seen[$identity]],
                );

                continue;
            }
            if ($rd !== '') {
                $seenVrfRds[$identity] = (string) ($object['source_id'] ?? '');
            } else {
                $seenVrfNamesWithoutRd[$identity] = (string) ($object['source_id'] ?? '');
            }
            $tenant = PlannerIntent::reference('tenant', $object['tenant_source_id'] ?? null);
            $intents[] = PlannerIntent::object('vrf', $object, [
                'rd' => $rd !== '' ? $rd : null,
                'name' => $name,
            ], array_filter([
                'name' => $name,
                'rd' => $rd !== '' ? $rd : null,
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
            $vid = $object['vid'] ?? null;
            if (! is_int($vid) && ! (is_string($vid) && ctype_digit($vid))) {
                $intents[] = PlannerIntent::issue('vlan_vid_invalid_preserved', 'vlan', $object['source_id'] ?? null, ['vid' => $vid]);

                continue;
            }
            if ((int) $vid < 1 || (int) $vid > 4094) {
                $intents[] = PlannerIntent::issue('vlan_vid_out_of_range_preserved', 'vlan', $object['source_id'] ?? null, ['vid' => (int) $vid]);

                continue;
            }
            $group = PlannerIntent::reference('vlan_group', $object['vlan_group_source_id'] ?? null);
            $tenant = PlannerIntent::reference('tenant', $object['tenant_source_id'] ?? null);
            $intents[] = PlannerIntent::object('vlan', $object, [
                'vid' => (int) $vid,
                'group_id' => $group,
            ], array_filter([
                'vid' => (int) $vid,
                'name' => ($object['name'] ?? '') ?: 'VLAN '.($object['vid'] ?? ''),
                'status' => $policy->status('vlan', $object['source_status'] ?? null),
                'group' => $group,
                'tenant' => $tenant,
                'description' => $object['description'] ?? '',
            ], fn (mixed $value): bool => $value !== null));
        }

        $prefixes = $objects['prefixes'] ?? [];
        // A phpIPAM record can retain a tag ID even when no tag definition is
        // available through the selected adapter. Do not emit an unresolved
        // NetBox reference for that orphaned value.
        $hasSourceTags = ($objects['tags'] ?? []) !== [];
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
            if ($this->prefixLength($object['prefix'] ?? null) === 0) {
                $intents[] = PlannerIntent::issue(
                    'netbox_prefix_zero_length_preserved',
                    'prefix',
                    $object['source_id'] ?? null,
                    ['prefix' => $object['prefix'] ?? null],
                );

                continue;
            }
            $vrf = PlannerIntent::reference('vrf', $object['vrf_source_id'] ?? null);
            $vlan = PlannerIntent::reference('vlan', $object['vlan_source_id'] ?? null);
            $tenant = PlannerIntent::reference('tenant', $object['tenant_source_id'] ?? null);
            $tag = $policy->migrates('tag') && $hasSourceTags
                ? $this->tagReference($object['tag_source_id'] ?? null)
                : null;
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
                'tags' => $tag === null ? [] : [$tag],
            ], fn (mixed $value): bool => $value !== null));
        }
        foreach ($objects['ip_addresses'] ?? [] as $object) {
            if ($this->prefixLength($object['address'] ?? null) === 0) {
                $intents[] = PlannerIntent::issue(
                    'netbox_ip_address_zero_length_preserved',
                    'ip_address',
                    $object['source_id'] ?? null,
                    ['address' => $object['address'] ?? null],
                );

                continue;
            }
            $vrf = PlannerIntent::reference('vrf', $object['vrf_source_id'] ?? null);
            $tenant = PlannerIntent::reference('tenant', $object['tenant_source_id'] ?? null);
            $interface = PlannerIntent::reference('interface', $object['interface_source_id'] ?? null);
            $tag = $policy->migrates('tag') && $hasSourceTags
                ? $this->tagReference($object['tag_source_id'] ?? null)
                : null;
            $dnsName = trim((string) ($object['dns_name'] ?? ''));
            if ($dnsName !== '' && ! $this->validDnsName($dnsName)) {
                // Keep the IP address itself migratable. A malformed hostname
                // must remain auditable instead of producing a NetBox API
                // failure or being silently modified.
                $intents[] = PlannerIntent::issue(
                    'ip_dns_name_invalid_preserved',
                    'ip_address',
                    $object['source_id'] ?? null,
                    ['field' => 'dns_name'],
                );
                $dnsName = '';
            }
            $payload = array_filter([
                'address' => $object['address'] ?? null,
                'status' => $policy->status('ip_address', $object['source_status'] ?? null),
                'vrf' => $vrf,
                'tenant' => $tenant,
                'dns_name' => $dnsName,
                'description' => $object['description'] ?? '',
                'tags' => $tag === null ? [] : [$tag],
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

    private function prefixLength(mixed $value): ?int
    {
        if (! is_string($value) || ! str_contains($value, '/')) {
            return null;
        }
        [, $length] = explode('/', $value, 2);

        return ctype_digit($length) ? (int) $length : null;
    }

    private function validDnsName(string $value): bool
    {
        // RFC 1123 host labels, with a permitted terminal dot. This is
        // deliberately stricter than arbitrary text because NetBox treats
        // dns_name as an operational DNS identifier, not a note field.
        $value = rtrim($value, '.');

        if ($value === '' || mb_strlen($value) > 253) {
            return false;
        }

        foreach (explode('.', $value) as $label) {
            if ($label === '' || strlen($label) > 63
                || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function tagReference(mixed $sourceId): ?array
    {
        if (! is_scalar($sourceId) || $sourceId === '' || $sourceId === 0 || $sourceId === '0') {
            return null;
        }

        return PlannerIntent::reference('tag', 'tag:'.(string) $sourceId);
    }
}
