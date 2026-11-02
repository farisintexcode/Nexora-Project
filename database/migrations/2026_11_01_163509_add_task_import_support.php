<?php

use Database\Seeders\ImportRegistrySeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets Upload Task turn workbook rows into tasks under a chosen assignment.
 * Adds the task import kind, permits rows to record the task they created and publishes the task contract.
 */
return new class extends Migration
{
    private const LEGACY_KINDS = "'customer','assignment_batch','debt_account','assignment','payment_update','databank'";

    public function up(): void
    {
        $kinds = self::LEGACY_KINDS.",'task'";
        $this->replaceCheck('import_fields', "version > 0 AND module IN ($kinds)");
        $this->replaceCheck('import_templates', "version > 0 AND assignment_type_category = 'assignment_type' AND kind IN ($kinds)");
        $this->replaceCheck('import_rows', "row_number > 0 AND target_account_id IS NULL AND state IN ('staged','accepted','rejected','duplicate','processed','failed') AND (state <> 'processed' OR num_nonnulls(target_customer_id,target_assignment_batch_id,target_task_id) = 1)");
        $this->replaceRowGuard("IF NEW.target_task_id IS NOT NULL AND batch.kind <> 'task' THEN RAISE EXCEPTION 'Task scope mismatch' USING ERRCODE='23514'; END IF;");
        Model::unguarded(fn () => (new ImportRegistrySeeder)->run());
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE import_templates DISABLE TRIGGER import_template_immutable');
        DB::statement('ALTER TABLE import_fields DISABLE TRIGGER import_field_immutable');
        DB::table('import_templates')->where('kind', 'task')->delete();
        DB::table('import_fields')->where('module', 'task')->delete();
        DB::statement('ALTER TABLE import_fields ENABLE TRIGGER import_field_immutable');
        DB::statement('ALTER TABLE import_templates ENABLE TRIGGER import_template_immutable');
        $this->replaceRowGuard('');
        $this->replaceCheck('import_rows', "row_number > 0 AND target_task_id IS NULL AND target_account_id IS NULL AND state IN ('staged','accepted','rejected','duplicate','processed','failed') AND (state <> 'processed' OR num_nonnulls(target_customer_id,target_assignment_batch_id) = 1)");
        $this->replaceCheck('import_templates', "version > 0 AND assignment_type_category = 'assignment_type' AND kind IN (".self::LEGACY_KINDS.')');
        $this->replaceCheck('import_fields', 'version > 0 AND module IN ('.self::LEGACY_KINDS.')');
    }

    private function replaceCheck(string $table, string $check): void
    {
        DB::statement("ALTER TABLE $table DROP CONSTRAINT {$table}_values");
        DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_values CHECK ($check)");
    }

    private function replaceRowGuard(string $taskCheck): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION import_row_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE batch import_batches;
            BEGIN
                SELECT * INTO batch FROM import_batches WHERE id=COALESCE(NEW.import_batch_id,OLD.import_batch_id);
                IF TG_OP='DELETE' THEN
                    IF batch.confirmed_at IS NOT NULL THEN RAISE EXCEPTION 'Confirmed row immutable' USING ERRCODE='23514'; END IF;
                    RETURN OLD;
                END IF;
                IF TG_OP='INSERT' AND batch.confirmed_at IS NOT NULL THEN RAISE EXCEPTION 'Confirmed row set immutable' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (OLD.state='processed' OR (batch.confirmed_at IS NOT NULL AND (NEW.raw_payload_ciphertext,NEW.normalized_payload_ciphertext,NEW.sheet_name,NEW.row_number,NEW.import_batch_id) IS DISTINCT FROM (OLD.raw_payload_ciphertext,OLD.normalized_payload_ciphertext,OLD.sheet_name,OLD.row_number,OLD.import_batch_id))) THEN RAISE EXCEPTION 'Committed payload immutable' USING ERRCODE='23514'; END IF;
                IF NEW.target_customer_id IS NOT NULL AND batch.kind <> 'customer' THEN RAISE EXCEPTION 'Customer scope mismatch' USING ERRCODE='23514'; END IF;
                IF NEW.target_assignment_batch_id IS NOT NULL AND batch.kind <> 'assignment_batch' THEN RAISE EXCEPTION 'Batch scope mismatch' USING ERRCODE='23514'; END IF;
                $taskCheck
                RETURN NEW;
            END; \$\$;
        SQL);
    }
};
