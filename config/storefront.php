<?php

return [
    'api_url' => env('STOREFRONT_API_URL', 'http://rs-gateway:2901'),
    'token' => env('STOREFRONT_RESELLER_TOKEN', ''),
    'catalog_cache_path' => env('STOREFRONT_CATALOG_CACHE_PATH', storage_path('framework/cache/storefront')),
];
