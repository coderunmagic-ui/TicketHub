<?php

return [
    'enabled' => (bool) env('ADMISSION_ENABLED', true),
    'namespace' => env('ADMISSION_NAMESPACE', 'tickethub-v2'),
    'slots' => (int) env('ADMISSION_SLOTS', 30),
    'rate' => (int) env('ADMISSION_RATE', 10),
    'burst' => (int) env('ADMISSION_BURST', 10),
    'permit_seconds' => (int) env('ADMISSION_PERMIT_SECONDS', 90),
    'processing_seconds' => (int) env('ADMISSION_PROCESSING_SECONDS', 60),
    'heartbeat_seconds' => (int) env('ADMISSION_HEARTBEAT_SECONDS', 90),
    'max_waiters' => (int) env('ADMISSION_MAX_WAITERS', 50000),
    'token_seconds' => 3600,
    'catalog_seconds' => (int) env('CATALOG_CACHE_SECONDS', 5),
    'catalog_cache' => (bool) env('CATALOG_CACHE_ENABLED', true),
];
