<?php

namespace Database\Seeders;

use App\Models\ImportField;
use App\Models\ImportTemplate;
use App\Models\ReferenceValue;
use Illuminate\Database\Seeder;

/** M04: initial contracts reuse the currently implemented M03 and M05 writers. */
class ImportRegistrySeeder extends Seeder
{
    public const TASK_REQUIRED = ['account_number', 'customer_reference', 'task_id', 'outstanding_balance'];

    public const TASK_FIELDS = ['task_id', 'account_number', 'customer_reference', 'customer_name', 'phone', 'email', 'outstanding_balance', 'monthly_charge', 'balance_date', 'address', 'building', 'postcode', 'state_code', 'priority_code', 'instructions'];

    public function run(): void
    {
        foreach (['workbook' => 'Workbook', 'report' => 'Report'] as $code => $label) {
            ReferenceValue::query()->firstOrCreate(['category' => 'document_type', 'code' => $code], ['label' => $label]);
        }
        $contracts = [
            'assignment_batch' => ['external_reference', 'name', 'type_code', 'start_date', 'due_date', 'description', 'dca_code', 'dca_name', 'la_name', 'state_code'],
            'task' => self::TASK_FIELDS,
        ];
        $requiredFields = [
            'assignment_batch' => ['external_reference', 'name', 'type_code', 'start_date', 'due_date'],
            'task' => self::TASK_REQUIRED,
        ];
        foreach ($contracts as $kind => $fields) {
            $required = $requiredFields[$kind];
            $ids = [];
            foreach ($fields as $field) {
                $record = ImportField::query()->firstOrCreate(['module' => $kind, 'canonical_field' => $field, 'version' => 1], ['data_type' => str_ends_with($field, '_date') ? 'date' : 'string', 'validation_schema' => ['required' => in_array($field, $required, true), 'transforms' => ['exact', 'trim']]]);
                $ids[] = $record->id;
            }
            ImportTemplate::query()->firstOrCreate(['code' => $kind.'-v1', 'version' => 1], ['kind' => $kind, 'is_active' => true, 'schema_definition' => ['field_ids' => $ids, 'required' => $required, 'identity' => $required[0], 'update' => 'mapped-fields-only', 'transforms' => ['exact', 'trim']]]);
        }
    }
}
