<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** M10/UI59: additive yearly policies and recurring calendar; no historical backfill. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $t): void {
            $t->text('description')->nullable();
            $t->boolean('tracks_balance')->default(false);
        });
        Schema::create('leave_entitlements', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $t->integer('leave_year');
            $t->integer('days');
            $t->foreignId('configured_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->unique(['leave_type_id', 'leave_year']);
        });
        Schema::create('leave_balance_adjustments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('rider_id')->constrained('staff')->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $t->integer('leave_year');
            $t->integer('days');
            $t->text('reason');
            $t->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->index(['rider_id', 'leave_type_id', 'leave_year']);
        });
        Schema::create('attendance_recurring_assignments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('rider_id')->constrained('staff')->restrictOnDelete();
            $t->foreignId('policy_id')->constrained('attendance_policies')->restrictOnDelete();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->index(['rider_id', 'starts_on', 'ends_on']);
        });
        Schema::create('attendance_policy_holidays', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('policy_id')->constrained('attendance_policies')->restrictOnDelete();
            $t->date('service_date');
            $t->string('name', 100);
            $t->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['policy_id', 'service_date']);
        });
        DB::statement('ALTER TABLE leave_entitlements ADD CONSTRAINT leave_entitlement_values CHECK (leave_year BETWEEN 2000 AND 2200 AND days BETWEEN 0 AND 366)');
        DB::statement('ALTER TABLE leave_balance_adjustments ADD CONSTRAINT leave_adjustment_values CHECK (leave_year BETWEEN 2000 AND 2200 AND days BETWEEN -366 AND 366 AND days <> 0)');
        DB::statement('ALTER TABLE attendance_recurring_assignments ADD CONSTRAINT recurring_dates CHECK (ends_on >= starts_on)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_leave_balance_adjustment() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Leave adjustments are immutable; record a compensating adjustment'; END $$;
CREATE TRIGGER leave_adjustment_guard BEFORE UPDATE OR DELETE ON leave_balance_adjustments FOR EACH ROW EXECUTE FUNCTION guard_leave_balance_adjustment();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_policy_holidays');
        Schema::dropIfExists('attendance_recurring_assignments');
        Schema::dropIfExists('leave_balance_adjustments');
        DB::statement('DROP FUNCTION IF EXISTS guard_leave_balance_adjustment()');
        Schema::dropIfExists('leave_entitlements');
        Schema::table('leave_types', function (Blueprint $t): void {
            $t->dropColumn(['description', 'tracks_balance']);
        });
    }
};
