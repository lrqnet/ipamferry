<?php

namespace App\Domain\Migration;

final class ResourceRegistry
{
    private const RESOURCES = [
        'custom_field_choice_set' => ['endpoint' => 'extras/custom-field-choice-sets/', 'collection' => 'custom_field_choice_sets', 'phase' => 5],
        'custom_field' => ['endpoint' => 'extras/custom-fields/', 'collection' => 'custom_fields', 'phase' => 10],
        'tag' => ['endpoint' => 'extras/tags/', 'collection' => 'tags', 'phase' => 10],
        'tenant' => ['endpoint' => 'tenancy/tenants/', 'collection' => 'tenants', 'phase' => 10],
        'contact' => ['endpoint' => 'tenancy/contacts/', 'collection' => 'contacts', 'phase' => 10],
        'contact_role' => ['endpoint' => 'tenancy/contact-roles/', 'collection' => 'contact_roles', 'phase' => 10],
        'contact_assignment' => ['endpoint' => 'tenancy/contact-assignments/', 'collection' => 'contact_assignments', 'phase' => 15],
        'site' => ['endpoint' => 'dcim/sites/', 'collection' => 'sites', 'phase' => 20],
        'location' => ['endpoint' => 'dcim/locations/', 'collection' => 'locations', 'phase' => 20],
        'rack' => ['endpoint' => 'dcim/racks/', 'collection' => 'racks', 'phase' => 30],
        'manufacturer' => ['endpoint' => 'dcim/manufacturers/', 'collection' => 'manufacturers', 'phase' => 40],
        'device_type' => ['endpoint' => 'dcim/device-types/', 'collection' => 'device_types', 'phase' => 40],
        'device_role' => ['endpoint' => 'dcim/device-roles/', 'collection' => 'device_roles', 'phase' => 40],
        'device' => ['endpoint' => 'dcim/devices/', 'collection' => 'devices', 'phase' => 50],
        'interface' => ['endpoint' => 'dcim/interfaces/', 'collection' => 'interfaces', 'phase' => 60],
        'mac_address' => ['endpoint' => 'dcim/mac-addresses/', 'collection' => 'mac_addresses', 'phase' => 60],
        'provider' => ['endpoint' => 'circuits/providers/', 'collection' => 'providers', 'phase' => 70],
        'circuit_type' => ['endpoint' => 'circuits/circuit-types/', 'collection' => 'circuit_types', 'phase' => 70],
        'circuit' => ['endpoint' => 'circuits/circuits/', 'collection' => 'circuits', 'phase' => 70],
        'circuit_termination' => ['endpoint' => 'circuits/circuit-terminations/', 'collection' => 'circuit_terminations', 'phase' => 75],
        'rir' => ['endpoint' => 'ipam/rirs/', 'collection' => 'rirs', 'phase' => 80],
        'asn' => ['endpoint' => 'ipam/asns/', 'collection' => 'asns', 'phase' => 80],
        'vrf' => ['endpoint' => 'ipam/vrfs/', 'collection' => 'vrfs', 'phase' => 90],
        'vlan_group' => ['endpoint' => 'ipam/vlan-groups/', 'collection' => 'vlan_groups', 'phase' => 90],
        'vlan' => ['endpoint' => 'ipam/vlans/', 'collection' => 'vlans', 'phase' => 90],
        'prefix' => ['endpoint' => 'ipam/prefixes/', 'collection' => 'prefixes', 'phase' => 90],
        'ip_address' => ['endpoint' => 'ipam/ip-addresses/', 'collection' => 'ip_addresses', 'phase' => 90],
    ];

    public function all(): array
    {
        return self::RESOURCES;
    }

    public function endpoint(string $type): string
    {
        return self::RESOURCES[$type]['endpoint']
            ?? throw new \InvalidArgumentException("Unsupported NetBox target type: {$type}");
    }

    public function collection(string $type): string
    {
        return self::RESOURCES[$type]['collection']
            ?? throw new \InvalidArgumentException("Unsupported NetBox target type: {$type}");
    }

    public function phase(string $type): int
    {
        return self::RESOURCES[$type]['phase'] ?? 999;
    }
}
