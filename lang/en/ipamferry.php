<?php

return [
    'report' => [
        'title' => 'IpamFerry migration report',
        'summary' => ':actions actions, :conflicts conflicts, :warnings warnings.',
        'preservation' => 'Preservation report',
        'generated_at' => 'Generated at',
        'warning' => ':type requires mapping review before export to NetBox.',
        'warnings' => 'Warnings',
        'category' => 'Category',
        'objects' => 'Objects',
        'prefix_hierarchy' => 'Prefix hierarchy',
        'issues' => [
            'prefix_folder_preserved' => 'A phpIPAM folder has no safe NetBox equivalent and remains preserved.',
            'device_ip_without_port' => 'An IP linked to a device has no port and remains unassigned.',
            'pat_preserved' => 'PAT or port-based NAT remains preserved and is never partially converted.',
            'nat_confirmation_required' => 'A static NAT pair remains preserved until an operator confirms it.',
            'primary_ip_ambiguous' => 'Primary IP was not set because the source correspondence is ambiguous.',
        ],
    ],
];
