<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tracked leave type is limited either by working days (e.g. 20 days annual leave)
     * or by number of applications (e.g. medical leave 6 times a year).
     */
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $t): void {
            $t->string('limit_unit', 16)->default('days');
        });
        DB::statement("ALTER TABLE leave_types ADD CONSTRAINT leave_type_limit_unit CHECK (limit_unit IN ('days', 'applications'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE leave_types DROP CONSTRAINT IF EXISTS leave_type_limit_unit');
        Schema::table('leave_types', function (Blueprint $t): void {
            $t->dropColumn('limit_unit');
        });
    }
};
