<?php

/** M06 / UI22–23, UI48–52. Approved business policies live in reference_values metadata. */
return [
    // A local development computer usually has no S3 or ClamAV. Everywhere else fails closed, and production always scans.
    'disk' => env('PROOF_DISK', env('APP_ENV', 'production') === 'local' ? 'local' : 's3'),
    'scan_required' => (bool) env('PROOF_SCAN_REQUIRED', env('APP_ENV', 'production') !== 'local'),
    'max_image_kib' => 10240,
    'max_images' => 10,
    'max_dimension' => 8192,
    'max_pixels' => 24000000,
];
