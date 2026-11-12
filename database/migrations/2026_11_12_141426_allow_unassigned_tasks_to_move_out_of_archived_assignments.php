<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task identity stays immutable, with one exception: an untouched task (unstarted, never reviewed, no current Rider, not
 * excluded) may leave an archived assignment for an active assignment of the same type, so the unassigned customers of
 * an uploaded Excel file can be given out under a later assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->guard(<<<'SQL'
            IF TG_OP='UPDATE' AND NEW.assignment_batch_id IS DISTINCT FROM OLD.assignment_batch_id AND NOT (
                OLD.work_status='unstarted' AND OLD.review_status='none' AND NEW.work_status='unstarted' AND NEW.review_status='none' AND NOT OLD.excluded
                AND NOT EXISTS (SELECT 1 FROM task_allocations WHERE task_id=OLD.id AND released_at IS NULL)
                AND (SELECT status FROM assignment_batches WHERE id=OLD.assignment_batch_id)='archived'
                AND (SELECT status FROM assignment_batches WHERE id=NEW.assignment_batch_id)='active'
                AND (SELECT type_code FROM assignment_batches WHERE id=NEW.assignment_batch_id)=OLD.type_code
            ) THEN RAISE EXCEPTION 'Task identity/source immutable' USING ERRCODE='23514'; END IF;
            IF TG_OP='UPDATE' AND (NEW.source_snapshot_ciphertext,NEW.origin_code,NEW.source_import_row_id,NEW.source_task_id,NEW.source_task_number,NEW.customer_id,NEW.debt_account_id,NEW.created_by,NEW.type_code) IS DISTINCT FROM (OLD.source_snapshot_ciphertext,OLD.origin_code,OLD.source_import_row_id,OLD.source_task_id,OLD.source_task_number,OLD.customer_id,OLD.debt_account_id,OLD.created_by,OLD.type_code) THEN RAISE EXCEPTION 'Task identity/source immutable' USING ERRCODE='23514'; END IF;
            SQL);
    }

    public function down(): void
    {
        $this->guard(<<<'SQL'
            IF TG_OP='UPDATE' AND (NEW.source_snapshot_ciphertext,NEW.origin_code,NEW.source_import_row_id,NEW.source_task_id,NEW.source_task_number,NEW.customer_id,NEW.debt_account_id,NEW.assignment_batch_id,NEW.created_by,NEW.type_code) IS DISTINCT FROM (OLD.source_snapshot_ciphertext,OLD.origin_code,OLD.source_import_row_id,OLD.source_task_id,OLD.source_task_number,OLD.customer_id,OLD.debt_account_id,OLD.assignment_batch_id,OLD.created_by,OLD.type_code) THEN RAISE EXCEPTION 'Task identity/source immutable' USING ERRCODE='23514'; END IF;
            SQL);
    }

    private function guard(string $updateRules): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION dcms_task_scope_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
            IF TG_OP='INSERT' AND NOT EXISTS (SELECT 1 FROM account_parties WHERE debt_account_id=NEW.debt_account_id AND debtor_id=NEW.customer_id AND valid_from<=statement_timestamp() AND (valid_to IS NULL OR valid_to>statement_timestamp())) THEN RAISE EXCEPTION 'Customer not on account' USING ERRCODE='23514'; END IF;
            {$updateRules}
            RETURN NEW;
            END; \$\$
            SQL);
    }
};
