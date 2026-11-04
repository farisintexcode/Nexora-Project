<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives every assignment one permanent code, ASG-YY-NNNN, in place of the per-status register number.
 * Only the database generates codes: a supplied value is ignored, a change is refused, and a counter per
 * creation year means a code is never reused, including after a deletion. The counter row stays locked until
 * the creating transaction ends, so concurrent inserts cannot share a number and a rollback issues nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $timezone = DB::getPdo()->quote(config('dcms.business_timezone'));

        DB::unprepared(<<<SQL
            DROP TRIGGER assignment_register_number ON assignment_batches;
            DROP FUNCTION dcms_assignment_register_number();
            ALTER TABLE assignment_batches DROP CONSTRAINT assignment_batches_status_register_no_unique;
            ALTER TABLE assignment_batches DROP COLUMN register_no;
            DROP TABLE assignment_register_counters;

            CREATE TABLE assignment_code_counters (year smallint PRIMARY KEY, last_no integer NOT NULL);
            ALTER TABLE assignment_batches ADD COLUMN assignment_code varchar(16);

            CREATE OR REPLACE FUNCTION dcms_assignment_code(code_year integer, code_no integer) RETURNS varchar LANGUAGE sql IMMUTABLE AS $$
                SELECT 'ASG-' || lpad((code_year % 100)::text, 2, '0') || '-' || lpad(code_no::text, greatest(4, length(code_no::text)), '0');
            $$;

            WITH numbered AS (
                SELECT id, created_year, row_number() OVER (PARTITION BY created_year ORDER BY id)::integer AS position
                FROM (SELECT id, extract(year FROM created_at AT TIME ZONE {$timezone})::integer AS created_year FROM assignment_batches) AS created
            ), counted AS (
                INSERT INTO assignment_code_counters (year, last_no) SELECT created_year, max(position) FROM numbered GROUP BY created_year
            )
            UPDATE assignment_batches SET assignment_code = dcms_assignment_code(numbered.created_year, numbered.position) FROM numbered WHERE assignment_batches.id = numbered.id;

            ALTER TABLE assignment_batches ALTER COLUMN assignment_code SET NOT NULL;
            ALTER TABLE assignment_batches ADD CONSTRAINT assignment_batches_assignment_code_unique UNIQUE (assignment_code);

            CREATE OR REPLACE FUNCTION dcms_assignment_code_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                code_year integer;
                code_no integer;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.assignment_code IS DISTINCT FROM OLD.assignment_code THEN
                        RAISE EXCEPTION 'Assignment code cannot change' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                code_year := extract(year FROM NEW.created_at AT TIME ZONE {$timezone})::integer;
                INSERT INTO assignment_code_counters (year, last_no) VALUES (code_year, 1)
                ON CONFLICT (year) DO UPDATE SET last_no = assignment_code_counters.last_no + 1
                RETURNING last_no INTO code_no;
                NEW.assignment_code := dcms_assignment_code(code_year, code_no);
                RETURN NEW;
            END; $$;

            CREATE TRIGGER assignment_code_guard BEFORE INSERT OR UPDATE ON assignment_batches
            FOR EACH ROW EXECUTE FUNCTION dcms_assignment_code_guard();
        SQL);
    }

    /** Restores the register number implementation. Register numbers are regenerated and assignment codes are lost. */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER assignment_code_guard ON assignment_batches;
            DROP FUNCTION dcms_assignment_code_guard();
            ALTER TABLE assignment_batches DROP CONSTRAINT assignment_batches_assignment_code_unique;
            ALTER TABLE assignment_batches DROP COLUMN assignment_code;
            DROP FUNCTION dcms_assignment_code(integer, integer);
            DROP TABLE assignment_code_counters;

            CREATE TABLE assignment_register_counters (status varchar(24) PRIMARY KEY, last_no integer NOT NULL);
            ALTER TABLE assignment_batches ADD COLUMN register_no integer;

            WITH numbered AS (SELECT id, row_number() OVER (PARTITION BY status ORDER BY id) AS position FROM assignment_batches)
            UPDATE assignment_batches SET register_no = numbered.position FROM numbered WHERE assignment_batches.id = numbered.id;

            INSERT INTO assignment_register_counters (status, last_no)
            SELECT status, max(register_no) FROM assignment_batches GROUP BY status;

            ALTER TABLE assignment_batches ALTER COLUMN register_no SET NOT NULL;
            ALTER TABLE assignment_batches ADD CONSTRAINT assignment_batches_status_register_no_unique UNIQUE (status, register_no);

            CREATE OR REPLACE FUNCTION dcms_assignment_register_number() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.status IS NOT DISTINCT FROM OLD.status THEN
                    NEW.register_no := OLD.register_no;
                    RETURN NEW;
                END IF;
                INSERT INTO assignment_register_counters (status, last_no) VALUES (NEW.status, 1)
                ON CONFLICT (status) DO UPDATE SET last_no = assignment_register_counters.last_no + 1
                RETURNING last_no INTO NEW.register_no;
                RETURN NEW;
            END; $$;

            CREATE TRIGGER assignment_register_number BEFORE INSERT OR UPDATE ON assignment_batches
            FOR EACH ROW EXECUTE FUNCTION dcms_assignment_register_number();
        SQL);
    }
};
