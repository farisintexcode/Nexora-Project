<?php

/** Plan.md M04: bounded intake defaults, reviewed mappings, private storage and fail-closed scanning. */
return [
    'disk' => env('IMPORT_DISK', 'local'),
    'scan_required' => (bool) env('IMPORT_SCAN_REQUIRED', true),
    'max_kilobytes' => (int) env('IMPORT_MAX_KILOBYTES', 10240),
    'max_rows' => (int) env('IMPORT_MAX_ROWS', 2000),
    'max_columns' => 100,
    'max_sheets' => 10,
    'max_expanded_bytes' => 67108864,
    'scanner_host' => env('IMPORT_SCANNER_HOST', 'clamav'),
    'scanner_port' => (int) env('IMPORT_SCANNER_PORT', 3310),
    'scanner_timeout' => 30,
    'queue' => 'imports',
    // Workbook headers that suggest a field. Matching ignores case, spaces, underscores, hyphens and punctuation; every suggestion is still reviewed.
    'aliases' => [
        'task' => [
            'task_id' => ['Task', 'ID', 'Task No', 'Task Number', 'Task Ref', 'Task Reference', 'No Tugasan'],
            'account_number' => ['Account No', 'Account Num', 'Acc No', 'Acct No', 'Account', 'No Akaun', 'Nombor Akaun'],
            'customer_reference' => ['Customer Ref', 'Customer No', 'Customer Number', 'Customer ID', 'Cust Ref', 'Cust No', 'No Pelanggan', 'Rujukan Pelanggan'],
            'customer_name' => ['Customer Name', 'Cust Name', 'Full Name', 'Nama', 'Nama Pelanggan'],
            'phone' => ['Phone', 'Phone No', 'Phone Number', 'Mobile', 'Mobile No', 'Tel', 'Tel No', 'No Telefon'],
            'email' => ['Email', 'Email Address', 'Emel'],
            'outstanding_balance' => ['Outstanding', 'Outstanding Balance', 'Outstanding Amount', 'Balance', 'Amount Owing', 'Baki', 'Baki Tertunggak', 'Tunggakan'],
            'monthly_charge' => ['Monthly Charge', 'Monthly Bill', 'Monthly Usage', 'Caj Bulanan'],
            'balance_date' => ['Balance Date', 'As At', 'Statement Date', 'Tarikh Baki'],
            'address' => ['Address', 'Service Address', 'Alamat'],
            'building' => ['Building', 'Bangunan'],
            'postcode' => ['Postcode', 'Post Code', 'Poskod'],
            'state_code' => ['State', 'Negeri'],
            'priority_code' => ['Priority', 'Task Priority', 'Keutamaan'],
            'instructions' => ['Instruction', 'Special Instruction', 'Special Instructions', 'Task Instructions', 'Remarks', 'Notes', 'Arahan', 'Catatan'],
        ],
        'assignment_batch' => [
            'external_reference' => ['Batch Reference', 'Batch Ref', 'Assignment Reference', 'Assignment Ref', 'Reference', 'Ref No'],
            'name' => ['Batch Name', 'Assignment Name', 'Nama Tugasan'],
            'type_code' => ['Type', 'Assignment Type', 'Batch Type', 'Jenis Tugasan'],
            'start_date' => ['Start', 'Date Start', 'Tarikh Mula'],
            'due_date' => ['Due', 'End Date', 'Deadline', 'Tarikh Akhir'],
            'description' => ['Details', 'Keterangan'],
            'dca_code' => ['DCA'],
            'la_name' => ['LA', 'Local Authority', 'PBT'],
            'state_code' => ['State', 'Negeri'],
        ],
    ],
];
