<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numbers assignments separately per register tab. The database assigns register_no on insert and on a real
 * status change only, and a counter per status means a number is never reused within that status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_register_counters', function (Blueprint $table): void {
            $table->string('status', 24)->primary();
            $table->integer('last_no');
        });

        Schema::table('assignment_batches', function (Blueprint $table): void {
            $table->integer('register_no')->nullable();
        });

        DB::unprepared(<<<'SQL'
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

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER assignment_register_number ON assignment_batches;
            DROP FUNCTION dcms_assignment_register_number();
        SQL);

        Schema::table('assignment_batches', function (Blueprint $table): void {
            $table->dropUnique('assignment_batches_status_register_no_unique');
            $table->dropColumn('register_no');
        });

        Schema::dropIfExists('assignment_register_counters');
    }
};
