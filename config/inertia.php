<?php

return [
    'pages' => [
        'ensure_pages_exist' => false,
        'paths' => [
            resource_path('js/Pages'),
        ],
        'extensions' => [
            'js',
            'jsx',
            'ts',
            'tsx',
        ],
    ],
    'testing' => [
        'ensure_pages_exist' => true,
    ],
];
