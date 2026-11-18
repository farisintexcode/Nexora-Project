<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets admins delete assignments that have no field work. Tasks and imports keep their link, so a deleted assignment is
 * hidden and kept. Its external reference becomes available again; its assignment code is never reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE assignment_batches ADD COLUMN deleted_at timestamp(6) with time zone');
        DB::statement('DROP INDEX assignment_batches_external_reference_unique');
        DB::statement('CREATE UNIQUE INDEX assignment_batches_external_reference_unique ON assignment_batches (external_reference) WHERE external_reference IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        if (DB::table('assignment_batches')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('Deleted assignments exist. Their external references may have been reused, so this migration cannot be rolled back.');
        }
        DB::statement('DROP INDEX assignment_batches_external_reference_unique');
        DB::statement('CREATE UNIQUE INDEX assignment_batches_external_reference_unique ON assignment_batches (external_reference) WHERE external_reference IS NOT NULL');
        DB::statement('ALTER TABLE assignment_batches DROP COLUMN deleted_at');
    }
};
