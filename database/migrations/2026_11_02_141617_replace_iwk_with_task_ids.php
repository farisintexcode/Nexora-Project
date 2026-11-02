<?php

use Database\Seeders\ImportRegistrySeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the legacy IWK identifier with task IDs such as FG001, supplied by the workbook or typed on Create Task.
 * The trailing digits are stored as the number Assign Task selects by, unique within each assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tasks RENAME COLUMN source_iwk_id TO source_task_id');
        DB::statement('ALTER TABLE tasks ADD COLUMN source_task_number bigint');
        DB::statement('CREATE UNIQUE INDEX task_number_unique ON tasks (assignment_batch_id, source_task_number) WHERE source_task_number IS NOT NULL');
        $this->rewriteCheck('tasks', ['((source_task_id IS NULL) AND (source_import_row_id IS NULL))' => '(source_import_row_id IS NULL)']);
        $this->freezeTaskNumber();

        $this->rewriteCheck('bulk_operations', [
            "'range'::character varying]" => "'range'::character varying, 'range_selected'::character varying]",
            "((((selection_mode)::text = 'range'::text) AND (from_iwk_id IS NOT NULL) AND (to_iwk_id IS NOT NULL)) OR (((selection_mode)::text <> 'range'::text) AND (from_iwk_id IS NULL) AND (to_iwk_id IS NULL))) AND " => '',
        ]);
        DB::statement('ALTER TABLE bulk_operations DROP COLUMN from_iwk_id, DROP COLUMN to_iwk_id');

        $this->publishTaskContract();
    }

    public function down(): void
    {
        throw new RuntimeException('Replacing IWK identifiers cannot be rolled back. Restore a database backup taken before this migration.');
    }

    /** @param  array<string, string>  $replacements */
    private function rewriteCheck(string $table, array $replacements): void
    {
        $definition = DB::selectOne('SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?', ["{$table}_values"])->definition;
        foreach ($replacements as $search => $replacement) {
            if (! str_contains($definition, $search)) {
                throw new RuntimeException("The {$table} check no longer matches the expected definition.");
            }
            $definition = str_replace($search, $replacement, $definition);
        }
        DB::statement("ALTER TABLE $table DROP CONSTRAINT {$table}_values");
        DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_values $definition");
    }

    private function freezeTaskNumber(): void
    {
        $definition = DB::selectOne("SELECT pg_get_functiondef('dcms_task_scope_guard'::regproc) AS definition")->definition;
        DB::unprepared(str_replace(['NEW.source_iwk_id', 'OLD.source_iwk_id'], ['NEW.source_task_id,NEW.source_task_number', 'OLD.source_task_id,OLD.source_task_number'], $definition));
    }

    private function publishTaskContract(): void
    {
        DB::statement('ALTER TABLE import_templates DISABLE TRIGGER import_template_immutable');
        DB::statement('ALTER TABLE import_fields DISABLE TRIGGER import_field_immutable');
        Model::unguarded(fn () => (new ImportRegistrySeeder)->run());
        $fields = DB::table('import_fields')->where('module', 'task')->whereIn('canonical_field', ImportRegistrySeeder::TASK_FIELDS)->orderBy('id')->pluck('id')->all();
        foreach (DB::table('import_templates')->where('kind', 'task')->get(['id', 'schema_definition']) as $template) {
            $definition = json_decode($template->schema_definition, true, flags: JSON_THROW_ON_ERROR);
            DB::table('import_templates')->where('id', $template->id)->update(['schema_definition' => json_encode(['field_ids' => $fields, 'required' => ImportRegistrySeeder::TASK_REQUIRED] + $definition, JSON_THROW_ON_ERROR)]);
        }
        DB::statement('ALTER TABLE import_fields ENABLE TRIGGER import_field_immutable');
        DB::statement('ALTER TABLE import_templates ENABLE TRIGGER import_template_immutable');
    }
};
