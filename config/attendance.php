<?php

return [
    /** Module.md#m10-approved-policy; used only for the initial draft, never live calculations. */
    'initial_code' => 'standard',
    'initial_rules' => ['workdays' => [1, 2, 3, 4, 5], 'shift_start' => '09:00', 'shift_end' => '18:00', 'break_minutes' => 60, 'grace_minutes' => 15, 'present_minutes' => 420, 'half_day_minutes' => 240, 'overnight_allowed' => true],

    /** States whose weekend is Friday and Saturday: a policy's workdays move one day earlier (Mon–Fri becomes Sun–Thu). */
    'friday_weekend_states' => ['kedah', 'kelantan', 'terengganu'],

    /** Leave supporting documents: private S3 in production; a development computer (APP_ENV=local) stores them encrypted on the local disk and skips the malware scan. */
    'document_disk' => env('LEAVE_DOCUMENT_DISK', env('APP_ENV', 'production') === 'local' ? 'local' : 's3'),
    'document_scan_required' => (bool) env('LEAVE_DOCUMENT_SCAN_REQUIRED', env('APP_ENV', 'production') !== 'local'),

    /** Days the nightly job looks back to fill calendar days that were missed (for example, while the scheduler was down). */
    'backfill_days' => 7,
];
