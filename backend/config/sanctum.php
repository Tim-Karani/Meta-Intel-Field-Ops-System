<?php

return [
    'stateful' => [], 'guard' => [],
    'expiration' => (int) env('SANCTUM_TOKEN_TTL_MINUTES', 1440),
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
    'middleware' => [],
];
