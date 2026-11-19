<?php

return [
    'bucket' => env('RELEASE_CONTROL_BUCKET', ''),
    'object' => env('RELEASE_CONTROL_OBJECT', 'control.json'),
    'environment' => env('RELEASE_CONTROL_ENVIRONMENT', env('APP_ENV', 'production')),
    'protected_bucket' => env('RELEASE_CONTROL_PROTECTED_BUCKET'),
    'project_id' => env('GOOGLE_CLOUD_PROJECT_ID'),
    'read_lease_seconds' => 5,
    'request_timeout_seconds' => 2,
];
