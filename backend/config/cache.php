<?php

return [
    'default' => env('CACHE_STORE', 'file'),
    'stores' => [
        'array' => ['driver' => 'array', 'serialize' => false],
        'file' => ['driver' => 'file', 'path' => storage_path('framework/cache/data')],
        'database' => ['driver' => 'database', 'table' => 'cache', 'lock_table' => 'cache_locks'],
    ],
    'prefix' => env('CACHE_PREFIX', 'erp_cache_'),
];
