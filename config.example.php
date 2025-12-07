<?php
// S3 Server Configuration Example
return [
    'region' => 'ap-southeast-1',
    'access_key' => 'RANDOM_UUID',
    'secret_key' => 'RANDOM_UUID_OR_RANDOM_STRING',
    'base_dir' => __DIR__ . '/data',
    // Temporary Buckets Configuration
    // Format: 'bucket_name' => hours_to_keep
    // Files in these buckets will be deleted after the specified hours.
    // Buckets not listed here are permanent.
    'temp_buckets' => [
        'ryzumi-api' => 24, // 24 hours
        'my-files' => 72,   // 3 days
    ],
];
