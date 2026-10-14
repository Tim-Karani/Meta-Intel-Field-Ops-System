<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => ['driver' => 'local', 'root' => storage_path('app/private'), 'serve' => false, 'throw' => true, 'report' => false],
        // Evidence is NEVER public: files are streamed through an authorised controller.
        'evidence' => ['driver' => 'local', 'root' => env('EVIDENCE_PATH', storage_path('app/private/evidence')), 'serve' => false, 'throw' => true, 'report' => false],
        's3' => ['driver' => 's3', 'key' => env('AWS_ACCESS_KEY_ID'), 'secret' => env('AWS_SECRET_ACCESS_KEY'), 'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'), 'endpoint' => env('AWS_ENDPOINT'), 'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false), 'throw' => true],
    ],
    'links' => [],
];
