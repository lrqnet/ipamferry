<?php

namespace App\Domain\Migration;

class SourceNormalizer
{
    public function normalize(array $inventory): array
    {
        $objects = $inventory['objects'] ?? $inventory;
        $subnetsById = collect($objects['subnets'] ?? [])->keyBy(fn (array $item): string => (string) ($item['id'] ?? ''));

        $normalized = [
            'vrfs' => array_values(array_map($this->normalizeVrf(...), $objects['vrfs'] ?? [])),
            'vlan_groups' => array_values(array_map($this->normalizeVlanGroup(...), $objects['l2domains'] ?? [])),
            'vlans' => array_values(array_map($this->normalizeVlan(...), $objects['vlans'] ?? [])),
            'prefixes' => [],
            'ip_addresses' => [],
        ];

        foreach ($objects['subnets'] ?? [] as $subnet) {
            $normalized['prefixes'][] = $this->normalizePrefix($subnet);
        }

        foreach ($objects['addresses'] ?? [] as $address) {
            $normalized['ip_addresses'][] = $this->normalizeIpAddress($address, $subnetsById->all());
        }

        $preserved = [];
        foreach ($objects as $type => $rows) {
            if (in_array($type, ['vrfs', 'l2domains', 'vlans', 'subnets', 'addresses'], true) || $rows === []) {
                continue;
            }

            $preserved[$type] = array_values(array_map(
                fn (array $row): array => $this->sanitize($row),
                is_array($rows) ? $rows : [],
            ));
        }

        return [
            'schema_version' => 1,
            'instance' => $inventory['instance'] ?? null,
            'normalized_at' => now()->toIso8601String(),
            'objects' => $normalized,
            'custom_fields' => $this->sanitize($inventory['custom_fields'] ?? []),
            'preserved' => $preserved,
            'warnings' => array_values($inventory['warnings'] ?? []),
        ];
    }

    private function normalizeVrf(array $source): array
    {
        return $this->canonical(
            'vrf',
            $source,
            [
                'name' => trim((string) ($source['name'] ?? '')),
                'rd' => trim((string) ($source['rd'] ?? '')) ?: null,
                'description' => trim((string) ($source['description'] ?? '')),
            ],
        );
    }

    private function normalizeVlanGroup(array $source): array
    {
        return $this->canonical(
            'vlan_group',
            $source,
            [
                'name' => trim((string) ($source['name'] ?? $source['description'] ?? '')),
                'description' => trim((string) ($source['description'] ?? '')),
                'scope' => null,
            ],
        );
    }

    private function normalizeVlan(array $source): array
    {
        return $this->canonical(
            'vlan',
            $source,
            [
                'vid' => $this->nullableInt($source['number'] ?? $source['vid'] ?? null),
                'name' => trim((string) ($source['name'] ?? '')),
                'description' => trim((string) ($source['description'] ?? '')),
                'vlan_group_source_id' => $this->reference($source['domainId'] ?? $source['domain_id'] ?? null),
            ],
        );
    }

    private function normalizePrefix(array $source): array
    {
        return $this->canonical(
            'prefix',
            $source,
            [
                'prefix' => $this->prefix($source),
                'description' => trim((string) ($source['description'] ?? '')),
                'vrf_source_id' => $this->reference($source['vrfId'] ?? $source['vrf_id'] ?? null),
                'vlan_source_id' => $this->reference($source['vlanId'] ?? $source['vlan_id'] ?? null),
                'parent_source_id' => $this->reference($source['masterSubnetId'] ?? $source['master_subnet_id'] ?? null),
                'section_source_id' => $this->reference($source['sectionId'] ?? $source['section_id'] ?? null),
                'location_source_id' => $this->reference($source['location'] ?? $source['location_id'] ?? null),
                'source_status' => $source['state'] ?? null,
                'is_folder' => $this->truthy($source['isFolder'] ?? $source['is_folder'] ?? false),
                'is_pool' => $this->truthy($source['isPool'] ?? $source['is_pool'] ?? false),
                'mark_utilized' => $this->truthy($source['isFull'] ?? false),
            ],
        );
    }

    private function normalizeIpAddress(array $source, array $subnetsById): array
    {
        $subnetId = $this->reference($source['subnetId'] ?? $source['subnet_id'] ?? null);
        $subnet = $subnetId === null ? null : ($subnetsById[$subnetId] ?? null);
        $mask = $subnet['mask'] ?? $subnet['prefix_length'] ?? null;
        $preferIpv6 = is_numeric($mask) && (int) $mask > 32;
        $ip = $this->ip(
            (string) (($source['ip_addr_v6'] ?? null) ?: ($source['ip'] ?? null) ?: ($source['ip_addr'] ?? '')),
            $preferIpv6,
        );

        return $this->canonical(
            'ip_address',
            $source,
            [
                'address' => $ip !== '' && $mask !== null ? "{$ip}/{$mask}" : null,
                'prefix_source_id' => $subnetId,
                'vrf_source_id' => $this->reference($subnet['vrfId'] ?? $subnet['vrf_id'] ?? null),
                'dns_name' => trim((string) ($source['hostname'] ?? '')),
                'description' => trim((string) ($source['description'] ?? '')),
                'source_status' => $source['tag'] ?? $source['state'] ?? null,
                'is_gateway' => $this->truthy($source['is_gateway'] ?? false),
                'device_source_id' => $this->reference($source['deviceId'] ?? $source['switch'] ?? null),
                'nat_source' => $source['NAT_address'] ?? null,
            ],
        );
    }

    private function canonical(string $type, array $source, array $fields): array
    {
        $sourceId = (string) ($source['id'] ?? $source['vlanId'] ?? $source['vrfId'] ?? '');
        $safeSource = $this->sanitize($source);

        return [
            'source_type' => $type,
            'source_id' => $sourceId,
            ...$fields,
            'legacy' => $safeSource,
            'source_hash' => CanonicalJson::fingerprint($safeSource),
        ];
    }

    private function prefix(array $source): ?string
    {
        $mask = $source['mask'] ?? $source['prefix_length'] ?? null;
        $address = $this->ip(
            (string) ($source['subnet'] ?? $source['subnet_addr'] ?? ''),
            is_numeric($mask) && (int) $mask > 32,
        );

        if ($address === '' || $mask === null || ! is_numeric($mask)) {
            return null;
        }

        $mask = (int) $mask;
        $maximum = str_contains($address, ':') ? 128 : 32;

        return $mask >= 0 && $mask <= $maximum
            ? $this->networkAddress($address, $mask)."/{$mask}"
            : null;
    }

    private function ip(string $value, bool $preferIpv6 = false): string
    {
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '0x') && ctype_xdigit(substr($value, 2))) {
            $packed = hex2bin(substr($value, 2));

            return $packed === false ? '' : (inet_ntop(str_pad($packed, 16, "\0", STR_PAD_LEFT)) ?: '');
        }

        if (strlen($value) === 16 && preg_match('/[^\x20-\x7E]/', $value)) {
            return inet_ntop($value) ?: '';
        }

        if (ctype_digit($value)) {
            if (! $preferIpv6 && strlen($value) <= 10) {
                $numeric = (int) $value;
                if ($numeric >= 0 && $numeric <= 4_294_967_295) {
                    return long2ip($numeric) ?: '';
                }
            }

            $packed = $this->decimalToPacked($value);

            return $packed === null ? '' : (inet_ntop($packed) ?: '');
        }

        return filter_var($value, FILTER_VALIDATE_IP) ? $value : '';
    }

    private function decimalToPacked(string $decimal): ?string
    {
        $decimal = ltrim($decimal, '0');
        if ($decimal === '') {
            return str_repeat("\0", 16);
        }

        $bytes = '';
        while ($decimal !== '') {
            $quotient = '';
            $remainder = 0;
            foreach (str_split($decimal) as $digit) {
                $number = ($remainder * 10) + (int) $digit;
                $next = intdiv($number, 256);
                $remainder = $number % 256;
                if ($quotient !== '' || $next !== 0) {
                    $quotient .= (string) $next;
                }
            }
            $bytes = chr($remainder).$bytes;
            if (strlen($bytes) > 16) {
                return null;
            }
            $decimal = $quotient;
        }

        return str_pad($bytes, 16, "\0", STR_PAD_LEFT);
    }

    private function networkAddress(string $address, int $mask): string
    {
        $packed = inet_pton($address);
        if ($packed === false) {
            return $address;
        }

        $remaining = $mask;
        $network = '';
        foreach (str_split($packed) as $byte) {
            $bits = min(8, max(0, $remaining));
            $network .= chr(ord($byte) & ($bits === 0 ? 0 : (0xFF << (8 - $bits))));
            $remaining -= $bits;
        }

        return inet_ntop($network) ?: $address;
    }

    private function sanitize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/(?:password|passwd|(?:^|[_-])pass(?:$|[_-])|token|secret|(?:api|access|private)[_-]?key|credential|community)/i', $key)) {
                continue;
            }
            $result[$key] = $this->sanitize($item);
        }

        return $result;
    }

    private function reference(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        return (string) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes'], true);
    }
}
