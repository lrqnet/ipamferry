<?php

namespace App\Domain\Migration;

class SourceNormalizer
{
    public function normalize(array $inventory): array
    {
        $objects = $inventory['objects'] ?? $inventory;
        $subnetsById = collect($objects['subnets'] ?? [])->keyBy(fn (array $item): string => (string) ($item['id'] ?? ''));
        $warnings = array_values($inventory['warnings'] ?? []);
        $preserved = [];
        $sensitiveExcluded = [];

        $normalized = [
            'customers' => array_values(array_map($this->normalizeCustomer(...), $objects['customers'] ?? [])),
            'sections' => array_values(array_map($this->normalizeSection(...), $objects['sections'] ?? [])),
            'tags' => array_values(array_map($this->normalizeTag(...), $objects['tags'] ?? [])),
            'locations' => array_values(array_map($this->normalizeLocation(...), $objects['locations'] ?? [])),
            'racks' => array_values(array_map($this->normalizeRack(...), $objects['racks'] ?? [])),
            'device_roles' => array_values(array_map($this->normalizeDeviceRole(...), $objects['device_types'] ?? [])),
            'devices' => array_values(array_map($this->normalizeDevice(...), $objects['devices'] ?? [])),
            'interfaces' => [],
            'mac_addresses' => [],
            'providers' => array_values(array_map($this->normalizeProvider(...), $objects['circuit_providers'] ?? [])),
            'circuit_types' => array_values(array_map($this->normalizeCircuitType(...), $objects['circuit_types'] ?? [])),
            'circuits' => array_values(array_map($this->normalizeCircuit(...), $objects['circuits'] ?? [])),
            'asns' => $this->normalizeAsns($objects['routing_bgp'] ?? []),
            'vrfs' => array_values(array_map($this->normalizeVrf(...), $objects['vrfs'] ?? [])),
            'vlan_groups' => array_values(array_map($this->normalizeVlanGroup(...), $objects['l2domains'] ?? [])),
            'vlans' => array_values(array_map($this->normalizeVlan(...), $objects['vlans'] ?? [])),
            'prefixes' => [],
            'ip_addresses' => [],
            'nat_relations' => [],
        ];

        foreach ($objects['subnets'] ?? [] as $subnet) {
            $normalized['prefixes'][] = $this->normalizePrefix($subnet);
        }

        foreach ($objects['addresses'] ?? [] as $address) {
            $ip = $this->normalizeIpAddress($address, $subnetsById->all());
            $normalized['ip_addresses'][] = $ip;
            $interface = $this->normalizeInterfaceFromAddress($address);
            if ($interface !== null) {
                $normalized['interfaces'][$interface['source_id']] = $interface;
                $mac = $this->normalizeMacFromAddress($address, $interface);
                if ($mac !== null) {
                    $normalized['mac_addresses'][$mac['source_id']] = $mac;
                } elseif (trim((string) ($address['mac'] ?? '')) !== '') {
                    $preserved['invalid_mac_addresses'][] = $this->preservedMac($address, 'invalid_format');
                    $warnings[] = "Invalid MAC address on phpIPAM address {$ip['source_id']} was preserved and not migrated.";
                }
            } elseif (trim((string) ($address['mac'] ?? '')) !== '') {
                $preserved['invalid_mac_addresses'][] = $this->preservedMac($address, 'interface_missing');
                $warnings[] = "MAC address on phpIPAM address {$ip['source_id']} has no device port and was preserved.";
            }
            if (($ip['nat_source'] ?? null) !== null) {
                $normalized['nat_relations'][] = $this->canonical('nat', $address, [
                    'inside_ip_source_id' => (string) $ip['nat_source'],
                    'outside_ip_source_id' => (string) $ip['source_id'],
                    'source_kind' => 'address',
                    'has_ports' => false,
                ]);
            }
        }
        foreach ($objects['nat'] ?? [] as $nat) {
            $normalized['nat_relations'][] = $this->normalizeNat($nat);
        }
        $normalized['nat_relations'] = $this->resolveNatReferences(
            $normalized['nat_relations'],
            $normalized['ip_addresses'],
        );
        $normalized['interfaces'] = array_values($normalized['interfaces']);
        $normalized['mac_addresses'] = array_values($normalized['mac_addresses']);

        foreach ($objects as $type => $rows) {
            if (in_array($type, [
                'customers',
                'sections',
                'tags',
                'locations',
                'racks',
                'device_types',
                'devices',
                'circuit_providers',
                'circuit_types',
                'circuits',
                'routing_bgp',
                'vrfs',
                'l2domains',
                'vlans',
                'subnets',
                'addresses',
                'nat',
            ], true) || $rows === []) {
                continue;
            }

            if ($this->isSensitiveExcludedObjectType((string) $type)) {
                $sensitiveExcluded[(string) $type] = [
                    'classification' => 'sensitive_excluded',
                    'count' => is_array($rows) ? count($rows) : 0,
                ];
                $warnings[] = "phpIPAM {$type} records were excluded because this module can contain sensitive values.";

                continue;
            }

            $preserved[$type] = array_values(array_map(
                fn (array $row): array => $this->sanitize($row),
                is_array($rows) ? $rows : [],
            ));
        }
        if (($objects['routing_bgp'] ?? []) !== []) {
            $preserved['bgp_sessions'] = array_values(array_map(
                fn (array $row): array => $this->sanitize($row),
                $objects['routing_bgp'],
            ));
        }

        return [
            'schema_version' => 2,
            // API discovery metadata can contain the application's permission
            // scope. It is operationally useful only during discovery and must
            // never become part of a project snapshot or export.
            'instance' => $this->sanitize($inventory['instance'] ?? null),
            'normalized_at' => now()->toIso8601String(),
            'objects' => $normalized,
            'custom_fields' => $this->sanitize($inventory['custom_fields'] ?? []),
            'preserved' => $preserved,
            'sensitive_excluded' => $sensitiveExcluded,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function normalizeCustomer(array $source): array
    {
        return $this->canonical('customer', $source, [
            'name' => trim((string) ($source['title'] ?? $source['name'] ?? $source['company'] ?? '')),
            'description' => (string) ($source['description'] ?? $source['comment'] ?? ''),
            'contact_name' => trim((string) ($source['contact_person'] ?? $source['contact'] ?? '')),
            'contact_email' => trim((string) ($source['contact_mail'] ?? $source['email'] ?? '')),
            'contact_phone' => trim((string) ($source['contact_phone'] ?? $source['phone'] ?? '')),
            'address' => trim((string) ($source['address'] ?? '')),
        ]);
    }

    private function normalizeSection(array $source): array
    {
        return $this->canonical('section', $source, [
            'name' => trim((string) ($source['name'] ?? '')),
            'description' => (string) ($source['description'] ?? ''),
            'parent_source_id' => $this->reference($source['masterSection'] ?? $source['master_section'] ?? null),
        ]);
    }

    private function normalizeTag(array $source): array
    {
        return $this->canonical('tag', $source, [
            'name' => trim((string) ($source['type'] ?? $source['name'] ?? $source['tag'] ?? '')),
            'description' => (string) ($source['description'] ?? ''),
            'color' => ltrim(trim((string) ($source['bgcolor'] ?? $source['color'] ?? '9e9e9e')), '#'),
            'source_status' => $source['id'] ?? null,
        ]);
    }

    private function normalizeLocation(array $source): array
    {
        return $this->canonical('location', $source, [
            'name' => trim((string) ($source['name'] ?? $source['title'] ?? '')),
            'description' => (string) ($source['description'] ?? ''),
            'address' => trim((string) ($source['address'] ?? '')),
            'parent_source_id' => $this->reference($source['parent_id'] ?? $source['parent'] ?? null),
        ]);
    }

    private function normalizeRack(array $source): array
    {
        return $this->canonical('rack', $source, [
            'name' => trim((string) ($source['name'] ?? $source['title'] ?? '')),
            'description' => (string) ($source['description'] ?? ''),
            'location_source_id' => $this->reference($source['location'] ?? $source['location_id'] ?? null),
            'u_height' => $this->nullableInt($source['size'] ?? $source['height'] ?? null),
            'row' => trim((string) ($source['row'] ?? '')),
        ]);
    }

    private function normalizeDeviceRole(array $source): array
    {
        // phpIPAM deviceTypes identifies rows with `tid` rather than the
        // conventional `id` used by most controllers and dump tables.
        $canonicalSource = (! isset($source['id']) || $source['id'] === '') && isset($source['tid'])
            ? [...$source, 'id' => $source['tid']]
            : $source;

        return $this->canonical('device_role', $canonicalSource, [
            'name' => trim((string) ($source['tname'] ?? $source['name'] ?? $source['type'] ?? '')),
            'description' => (string) ($source['tdescription'] ?? $source['description'] ?? ''),
        ]);
    }

    private function normalizeDevice(array $source): array
    {
        return $this->canonical('device', $source, [
            'name' => trim((string) ($source['hostname'] ?? $source['name'] ?? '')),
            'description' => (string) ($source['description'] ?? ''),
            'category_source_id' => $this->reference($source['type'] ?? $source['deviceType'] ?? null),
            'location_source_id' => $this->reference($source['location'] ?? $source['location_id'] ?? null),
            'rack_source_id' => $this->reference($source['rack'] ?? $source['rack_id'] ?? null),
            'rack_position' => is_numeric($source['rack_start'] ?? null) ? (float) $source['rack_start'] : null,
            'rack_face' => $this->truthy($source['rack_deep'] ?? false) ? 'rear' : 'front',
            'u_height' => $this->nullableInt($source['rack_size'] ?? null),
            'primary_ip_source' => $source['ip_addr'] ?? null,
        ]);
    }

    private function normalizeInterfaceFromAddress(array $source): ?array
    {
        $deviceId = $this->reference($source['deviceId'] ?? $source['switch'] ?? null);
        $port = trim((string) ($source['port'] ?? ''));
        if ($deviceId === null || $port === '') {
            return null;
        }
        $synthetic = [...$source, 'id' => "{$deviceId}:{$port}"];

        return $this->canonical('interface', $synthetic, [
            'name' => $port,
            'device_source_id' => $deviceId,
            'description' => '',
            'source_type_hint' => null,
        ]);
    }

    private function normalizeMacFromAddress(array $source, array $interface): ?array
    {
        $mac = strtoupper(str_replace('-', ':', trim((string) ($source['mac'] ?? ''))));
        if (preg_match('/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac) !== 1) {
            return null;
        }
        $synthetic = [...$source, 'id' => $interface['source_id'].':'.$mac];

        return $this->canonical('mac_address', $synthetic, [
            'mac_address' => $mac,
            'interface_source_id' => $interface['source_id'],
            'description' => '',
        ]);
    }

    private function preservedMac(array $source, string $reason): array
    {
        return [
            'source_id' => (string) ($source['id'] ?? ''),
            'device_source_id' => $this->reference($source['deviceId'] ?? $source['switch'] ?? null),
            'port' => mb_strimwidth(trim((string) ($source['port'] ?? '')), 0, 128, '…'),
            'mac_address' => mb_strimwidth(trim((string) ($source['mac'] ?? '')), 0, 64, '…'),
            'reason' => $reason,
        ];
    }

    private function normalizeProvider(array $source): array
    {
        return $this->canonical('provider', $source, [
            'name' => trim((string) ($source['name'] ?? $source['title'] ?? '')),
            'description' => (string) ($source['description'] ?? ''),
        ]);
    }

    private function normalizeCircuitType(array $source): array
    {
        return $this->canonical('circuit_type', $source, [
            'name' => trim((string) ($source['ctname'] ?? $source['name'] ?? $source['type'] ?? $source['title'] ?? '')),
            'description' => (string) ($source['description'] ?? ''),
        ]);
    }

    private function normalizeCircuit(array $source): array
    {
        return $this->canonical('circuit', $source, [
            'cid' => trim((string) ($source['cid'] ?? $source['circuit_id'] ?? $source['name'] ?? $source['id'] ?? '')),
            'provider_source_id' => $this->reference($source['provider'] ?? $source['provider_id'] ?? null),
            'type_source_id' => $this->reference($source['type'] ?? $source['type_id'] ?? null),
            'description' => (string) ($source['description'] ?? $source['comment'] ?? ''),
            'location_a_source_id' => $this->reference($source['location1'] ?? $source['location_a'] ?? $source['location'] ?? null),
            'location_z_source_id' => $this->reference($source['location2'] ?? $source['location_b'] ?? null),
            'status' => $source['status'] ?? null,
        ]);
    }

    private function normalizeAsns(array $rows): array
    {
        $asns = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['asn', 'local_as', 'remote_as', 'local_asn', 'remote_asn'] as $field) {
                $asn = $row[$field] ?? null;
                if (! is_numeric($asn) || (int) $asn < 1 || (int) $asn > 4_294_967_295) {
                    continue;
                }
                $synthetic = [...$row, 'id' => (string) (int) $asn];
                $asns[(string) (int) $asn] = $this->canonical('asn', $synthetic, [
                    'asn' => (int) $asn,
                    'description' => (string) ($row['description'] ?? ''),
                ]);
            }
        }

        return array_values($asns);
    }

    private function normalizeNat(array $source): array
    {
        $sourcePort = $source['src_port'] ?? $source['source_port'] ?? $source['port'] ?? null;
        $targetPort = $source['dst_port'] ?? $source['destination_port'] ?? $source['translated_port'] ?? null;

        return $this->canonical('nat', $source, [
            'inside_ip_source_id' => $this->reference($source['local_ip'] ?? $source['inside'] ?? $source['src'] ?? $source['source_id'] ?? null),
            'outside_ip_source_id' => $this->reference($source['public_ip'] ?? $source['outside'] ?? $source['dst'] ?? $source['destination_id'] ?? null),
            'source_kind' => 'nat_table',
            'has_ports' => $sourcePort !== null || $targetPort !== null,
        ]);
    }

    /**
     * phpIPAM NAT records commonly reference endpoint addresses as text, while
     * NetBox relations must refer to the stable source IDs of IP objects. Resolve
     * only an unambiguous host address; an ambiguous value deliberately remains
     * unresolved and is preserved by the relation planner.
     *
     * @param  list<array<string, mixed>>  $relations
     * @param  list<array<string, mixed>>  $addresses
     * @return list<array<string, mixed>>
     */
    private function resolveNatReferences(array $relations, array $addresses): array
    {
        $bySourceId = [];
        $byHost = [];
        foreach ($addresses as $address) {
            $sourceId = (string) ($address['source_id'] ?? '');
            $host = explode('/', (string) ($address['address'] ?? ''))[0];
            if ($sourceId === '' || $host === '') {
                continue;
            }
            $bySourceId[$sourceId] = $address;
            $byHost[$host][] = $address;
        }

        foreach ($relations as &$relation) {
            foreach (['inside' => 'inside_ip_source_id', 'outside' => 'outside_ip_source_id'] as $side => $field) {
                $raw = (string) ($relation[$field] ?? '');
                $match = $bySourceId[$raw] ?? null;
                if ($match === null) {
                    $candidates = $byHost[explode('/', $raw)[0]] ?? [];
                    $match = count($candidates) === 1 ? $candidates[0] : null;
                }
                if ($match === null) {
                    $relation[$field] = null;
                    $relation["{$side}_reference_ambiguous"] = $raw !== '';

                    continue;
                }
                $relation[$field] = (string) $match['source_id'];
                $relation["{$side}_vrf_source_id"] = $match['vrf_source_id'] ?? null;
            }
        }
        unset($relation);

        return $relations;
    }

    private function normalizeVrf(array $source): array
    {
        return $this->canonical(
            'vrf',
            $source,
            [
                'name' => trim((string) ($source['name'] ?? '')),
                'rd' => trim((string) ($source['rd'] ?? '')) ?: null,
                'description' => (string) ($source['description'] ?? ''),
                'tenant_source_id' => $this->reference($source['customer_id'] ?? $source['customerId'] ?? null),
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
                'description' => (string) ($source['description'] ?? ''),
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
                'description' => (string) ($source['description'] ?? ''),
                'vlan_group_source_id' => $this->reference($source['domainId'] ?? $source['domain_id'] ?? null),
                'tenant_source_id' => $this->reference($source['customer_id'] ?? $source['customerId'] ?? null),
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
                'description' => (string) ($source['description'] ?? ''),
                'vrf_source_id' => $this->reference($source['vrfId'] ?? $source['vrf_id'] ?? null),
                'vlan_source_id' => $this->reference($source['vlanId'] ?? $source['vlan_id'] ?? null),
                'parent_source_id' => $this->reference($source['masterSubnetId'] ?? $source['master_subnet_id'] ?? null),
                'section_source_id' => $this->reference($source['sectionId'] ?? $source['section_id'] ?? null),
                'location_source_id' => $this->reference($source['location'] ?? $source['location_id'] ?? null),
                'tenant_source_id' => $this->reference($source['customer_id'] ?? $source['customerId'] ?? null),
                'source_status' => $source['state'] ?? null,
                'is_folder' => $this->truthy($source['isFolder'] ?? $source['is_folder'] ?? false),
                'is_pool' => $this->truthy($source['isPool'] ?? $source['is_pool'] ?? false),
                'mark_utilized' => $this->truthy($source['isFull'] ?? false),
                'tag_source_id' => $this->reference($source['tag'] ?? $source['tag_id'] ?? null),
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
                'description' => (string) ($source['description'] ?? ''),
                'source_status' => $source['tag'] ?? $source['state'] ?? null,
                'tag_source_id' => $this->reference($source['tag'] ?? $source['tag_id'] ?? null),
                'is_gateway' => $this->truthy($source['is_gateway'] ?? false),
                'device_source_id' => $this->reference($source['deviceId'] ?? $source['switch'] ?? null),
                'interface_source_id' => ($this->reference($source['deviceId'] ?? $source['switch'] ?? null) !== null
                    && trim((string) ($source['port'] ?? '')) !== '')
                    ? $this->reference($source['deviceId'] ?? $source['switch'] ?? null).':'.trim((string) $source['port'])
                    : null,
                'tenant_source_id' => $this->reference($source['customer_id'] ?? $source['customerId'] ?? null),
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

        if (! filter_var($value, FILTER_VALIDATE_IP)) {
            return '';
        }

        $packed = inet_pton($value);

        return $packed === false ? '' : (inet_ntop($packed) ?: '');
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
            if (is_string($key) && preg_match('/(?:snmp_.*|password|passwd|(?:^|[_-])pass(?:$|[_-])|token|secret|(?:api|access|private)[_-]?key|credential|community|permissions?|users?(?:name|groups?)?)/i', $key)) {
                continue;
            }
            $result[$key] = $this->sanitize($item);
        }

        return $result;
    }

    private function isSensitiveExcludedObjectType(string $type): bool
    {
        return in_array(strtolower($type), [
            'api', 'api_keys', 'apikeys', 'permissions', 'scanagents', 'scan_agents',
            'user', 'users', 'usergroups', 'user_groups', 'vault', 'vaults',
        ], true);
    }

    private function reference(mixed $value): ?string
    {
        if (! is_scalar($value) || $value === '' || $value === 0 || $value === '0') {
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
