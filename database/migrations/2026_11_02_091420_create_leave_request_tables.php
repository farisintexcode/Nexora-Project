<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** M10 / Rider Attendance and Admin HR leave: staff/users, canonical documents and calendar links. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->timestampsTz(6);
            $t->string('name', 100)->unique();
            $t->boolean('is_active')->default(true);
            $t->boolean('requires_document')->default(false);
            $t->boolean('allow_past_dates')->default(false);
            $t->boolean('allow_pending_cancel')->default(false);
            $t->unsignedInteger('max_calendar_days');
            $t->unsignedInteger('lock_version')->default(0);
            $t->foreignId('configured_by')->constrained('users')->restrictOnDelete();
        });
        Schema::create('leave_requests', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->timestampsTz(6);
            $t->uuid('request_key')->unique();
            $t->foreignId('rider_id')->constrained('staff')->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $t->date('start_date');
            $t->date('end_date');
            $t->unsignedInteger('total_days');
            $t->text('reason');
            $t->jsonb('type_snapshot');
            $t->jsonb('calendar_snapshot');
            $t->foreignId('supporting_document_id')->nullable()->unique()->constrained('documents')->restrictOnDelete();
            $t->string('status', 20)->default('pending');
            $t->timestampTz('submitted_at', 6);
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('reviewed_at', 6)->nullable();
            $t->text('reviewer_remarks')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('cancelled_at', 6)->nullable();
            $t->unsignedInteger('lock_version')->default(0);
            $t->index(['rider_id', 'status', 'start_date']);
            $t->index(['status', 'submitted_at']);
        });
        Schema::create('leave_request_days', function (Blueprint $t): void {
            $t->foreignId('leave_request_id')->constrained('leave_requests')->restrictOnDelete();
            $t->foreignId('schedule_id')->constrained('attendance_schedules')->restrictOnDelete();
            $t->date('service_date');
            $t->boolean('charged');
            $t->primary(['leave_request_id', 'schedule_id']);
            $t->index('schedule_id');
        });
        DB::statement('ALTER TABLE leave_types ADD CONSTRAINT leave_type_rules CHECK (max_calendar_days BETWEEN 1 AND 93 AND lock_version >= 0)');
        DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_request_values CHECK (end_date>=start_date AND total_days>0 AND total_days<=93 AND lock_version>=0 AND status IN ('pending','approved','rejected','cancelled') AND (reviewed_by IS NULL OR reviewed_by<>requested_by) AND ((status='pending' AND reviewed_by IS NULL AND reviewed_at IS NULL AND cancelled_by IS NULL AND cancelled_at IS NULL) OR (status IN ('approved','rejected') AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND cancelled_by IS NULL AND cancelled_at IS NULL) OR (status='cancelled' AND cancelled_by=requested_by AND cancelled_at IS NOT NULL AND reviewed_by IS NULL AND reviewed_at IS NULL)) AND (status<>'rejected' OR reviewer_remarks IS NOT NULL))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_leave_request() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Leave history cannot be deleted'; END IF;
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'pending' THEN RAISE EXCEPTION 'Leave must start pending'; END IF;
  RETURN NEW;
 END IF;
 IF OLD.status <> 'pending' OR
 (to_jsonb(NEW) - ARRAY['status','reviewed_by','reviewed_at','reviewer_remarks','cancelled_by','cancelled_at','lock_version','updated_at']) IS DISTINCT FROM
 (to_jsonb(OLD) - ARRAY['status','reviewed_by','reviewed_at','reviewer_remarks','cancelled_by','cancelled_at','lock_version','updated_at']) THEN
 RAISE EXCEPTION 'Leave applications and final decisions are immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER leave_request_guard BEFORE INSERT OR UPDATE OR DELETE ON leave_requests FOR EACH ROW EXECUTE FUNCTION guard_leave_request();
SQL);
        DB::table('reference_values')->insertOrIgnore(['public_id' => (string) Str::uuid7(), 'category' => 'document_type', 'code' => 'leave_support', 'label' => 'Leave supporting document', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_days');
        Schema::dropIfExists('leave_requests');
        DB::statement('DROP FUNCTION IF EXISTS guard_leave_request()');
        Schema::dropIfExists('leave_types');
        if (! DB::table('documents')->where('document_type_code', 'leave_support')->exists()) {
            DB::table('reference_values')->where('category', 'document_type')->where('code', 'leave_support')->delete();
        }
    }
};
