<?php

return [

    'ssr' => [

        'enabled' => false,

        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),

        'ensure_bundle_exists' => false,

    ],

    'ensure_pages_exist' => false,

    'page_paths' => [

        resource_path('js/inertia/Pages'),

    ],

    'page_extensions' => [

        'js',
        'jsx',
        'ts',
        'tsx',

    ],

    'use_script_element_for_initial_page' => false,

    'testing' => [

        'ensure_pages_exist' => true,

        'page_paths' => [

            resource_path('js/inertia/Pages'),

        ],

        'page_extensions' => [

            'js',
            'jsx',
            'ts',
            'tsx',

        ],

    ],

    'history' => [

        'encrypt' => false,

    ],

];
