<?php

declare(strict_types=1);

return [

    // The Super Admin is an internal tool: client-side rendering only.
    'ssr' => [
        'enabled' => false,
    ],

    'pages' => [
        'ensure_pages_exist' => false,
        'paths' => [resource_path('js/admin/pages')],
        'extensions' => ['tsx'],
    ],

    'testing' => [
        'ensure_pages_exist' => true,
    ],

    'expose_shared_prop_keys' => true,

    'store_previous_url' => false,

    'history' => [
        'encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', true),
    ],

];
