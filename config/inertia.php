<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Inertia looks for page components under resources/js/pages. This
    | application keeps them under a template directory instead, chosen by
    | vite.config.js, so the default path resolves to nothing here.
    |
    | Only assertInertia()->component() reads this, and with the wrong path it
    | fails on a page that exists, which is why it is worth stating: the whole
    | block is declared because mergeConfigFrom replaces a top level key rather
    | than merging into it.
    |
    */

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [

            resource_path('templates/tailwindcss/js/Pages'),

        ],

        'extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

];
