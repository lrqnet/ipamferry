<?php

return [
    'report' => [
        'title' => 'Informe de migración de IpamFerry',
        'summary' => ':actions acciones, :conflicts conflictos, :warnings advertencias.',
        'preservation' => 'Informe de preservación',
        'generated_at' => 'Generado el',
        'warning' => ':type requiere revisión de mapeo antes de exportar a NetBox.',
        'warnings' => 'Advertencias',
        'category' => 'Categoría',
        'objects' => 'Objetos',
        'prefix_hierarchy' => 'Jerarquía de prefijos',
        'issues' => [
            'prefix_folder_preserved' => 'Una carpeta de phpIPAM no tiene equivalente seguro en NetBox y se conserva.',
            'device_ip_without_port' => 'Una IP vinculada a un dispositivo no tiene puerto y queda sin asignar.',
            'pat_preserved' => 'PAT o NAT con puertos se conserva y nunca se convierte parcialmente.',
            'nat_confirmation_required' => 'Un par NAT estático se conserva hasta que lo confirme un operador.',
            'primary_ip_ambiguous' => 'No se definió la IP primaria porque la correspondencia de origen es ambigua.',
        ],
    ],
];
