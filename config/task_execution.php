<?php

/** M06 / UI22–23, UI48–52. Approved business policies live in reference_values metadata. */
return [
    'disk' => env('PROOF_DISK', 's3'),
    'scan_required' => (bool) env('PROOF_SCAN_REQUIRED', true),
    'max_image_kib' => 10240,
    'max_images' => 10,
    'max_dimension' => 8192,
    'max_pixels' => 24000000,
];
