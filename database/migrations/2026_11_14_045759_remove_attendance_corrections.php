<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Attendance corrections are retired. Raw punches stay immutable, a shift's effective times change only through the
 * rider's own clock out, and a calendar day cannot be edited once it exists.
 */
return new class extends Migration
{
    /** Permissions that only governed correction requests and decisions. */
    private const RETIRED_PERMISSIONS = ['attendance.correct', 'attendance.approve_correction'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION attendance_history_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Attendance history is retained' USING ERRCODE='23514'; END IF;
                IF TG_TABLE_NAME='attendance_schedules' THEN
                    IF TG_OP='UPDATE' THEN RAISE EXCEPTION 'Attendance calendar is immutable' USING ERRCODE='23514'; END IF;
                    IF NOT EXISTS(SELECT 1 FROM attendance_policies WHERE id=NEW.policy_id AND is_active) THEN RAISE EXCEPTION 'Approved policy required' USING ERRCODE='23514'; END IF;
                    IF NEW.day_type='scheduled' AND EXISTS(SELECT 1 FROM attendance_schedules s WHERE s.rider_id=NEW.rider_id AND s.id<>COALESCE(NEW.id,0) AND s.day_type='scheduled' AND s.scheduled_start_at<NEW.scheduled_end_at AND s.scheduled_end_at>NEW.scheduled_start_at) THEN RAISE EXCEPTION 'Overlapping shift' USING ERRCODE='23514'; END IF;
                END IF;
                IF TG_TABLE_NAME='attendances' THEN
                    IF TG_OP='UPDATE' THEN
                        IF (NEW.id,NEW.rider_id,NEW.service_date,NEW.raw_clock_in_at,NEW.clock_in_request_id,NEW.public_id,NEW.remarks,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.rider_id,OLD.service_date,OLD.raw_clock_in_at,OLD.clock_in_request_id,OLD.public_id,OLD.remarks,OLD.created_at) OR (OLD.raw_clock_out_at IS NOT NULL AND (NEW.raw_clock_out_at,NEW.clock_out_request_id) IS DISTINCT FROM (OLD.raw_clock_out_at,OLD.clock_out_request_id)) OR (OLD.effective_clock_out_at IS NOT NULL AND OLD.raw_clock_out_at IS NULL AND NEW.raw_clock_out_at IS NOT NULL) THEN RAISE EXCEPTION 'Raw punches are immutable' USING ERRCODE='23514'; END IF;
                        IF NEW.lock_version<>OLD.lock_version+1 THEN RAISE EXCEPTION 'Attendance revision must advance' USING ERRCODE='23514'; END IF;
                        IF (NEW.schedule_id,NEW.effective_clock_in_at,NEW.effective_clock_out_at,NEW.status,NEW.worked_minutes,NEW.is_late,NEW.late_minutes) IS DISTINCT FROM (OLD.schedule_id,OLD.effective_clock_in_at,OLD.effective_clock_out_at,OLD.status,OLD.worked_minutes,OLD.is_late,OLD.late_minutes)
                        AND NOT (OLD.raw_clock_out_at IS NULL AND OLD.effective_clock_out_at IS NULL AND NEW.raw_clock_out_at IS NOT NULL AND NEW.effective_clock_out_at=NEW.raw_clock_out_at AND NEW.effective_clock_in_at=OLD.effective_clock_in_at AND NEW.schedule_id IS NOT DISTINCT FROM OLD.schedule_id) THEN RAISE EXCEPTION 'Effective times change only at clock out' USING ERRCODE='23514'; END IF;
                    END IF;
                    IF NEW.schedule_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM attendance_schedules WHERE id=NEW.schedule_id AND rider_id=NEW.rider_id AND service_date=NEW.service_date AND day_type='scheduled') THEN RAISE EXCEPTION 'Attendance schedule mismatch' USING ERRCODE='23514'; END IF;
                END IF;
                RETURN NEW;
            END; $$;
        SQL);
        Schema::drop('attendance_adjustments');
        Permission::query()->whereIn('name', self::RETIRED_PERMISSIONS)->where('guard_name', 'web')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        throw new RuntimeException('Attendance corrections were retired; use a reviewed forward migration to restore them.');
    }
};
