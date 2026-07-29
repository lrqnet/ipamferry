<?php

return [
    'report' => [
        'title' => 'Relatório de migração do IpamFerry',
        'summary' => ':actions ações, :conflicts conflitos, :warnings avisos.',
        'preservation' => 'Relatório de preservação',
        'generated_at' => 'Gerado em',
        'warning' => ':type requer revisão de mapeamento antes da exportação para o NetBox.',
        'warnings' => 'Avisos',
        'category' => 'Categoria',
        'objects' => 'Objetos',
        'prefix_hierarchy' => 'Hierarquia de prefixos',
        'issues' => [
            'prefix_folder_preserved' => 'Uma pasta do phpIPAM não possui equivalente seguro no NetBox e continua preservada.',
            'device_ip_without_port' => 'Um IP vinculado a device não possui porta e continua sem atribuição.',
            'pat_preserved' => 'PAT ou NAT com portas continua preservado e nunca é convertido parcialmente.',
            'nat_confirmation_required' => 'Um par NAT estático continua preservado até a confirmação de um operador.',
            'primary_ip_ambiguous' => 'O IP primário não foi definido porque a correspondência da origem é ambígua.',
        ],
    ],
];
