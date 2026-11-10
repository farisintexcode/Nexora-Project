<?php

namespace App\Services\Attendance;

use App\Actions\Attendance\ConfigureAttendance;
use App\Models\AttendancePolicy;
use App\Models\AttendanceSchedule;
use App\Models\Staff;
use App\Models\User;
use App\Services\AuditWriter;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** M10/UI59: authorized recurring assignments materialize canonical schedules; explicit dates take priority. */
class WorkCalendar
{
    public function assign(User $actor, string $staffId, string $policyId, string $from, string $until): void
    {
        abort_unless(app(LeaveAccess::class)->configure($actor->fresh()), 403);
        Validator::make(compact('staffId', 'policyId', 'from', 'until'), ['staffId' => ['required', 'uuid'], 'policyId' => ['required', 'uuid'], 'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now()->timezone(config('dcms.business_timezone'))->toDateString()], 'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']])->validate();
        DB::transaction(function () use ($actor, $staffId, $policyId, $from, $until): void {
            $staff = app(AttendanceAccess::class)->riders($actor)->where('public_id', $staffId)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $policy = AttendancePolicy::query()->where('public_id', $policyId)->where('is_active', true)->firstOrFail();
            if (DB::table('attendance_recurring_assignments')->where('rider_id', $staff->id)->where('starts_on', '<=', $until)->where('ends_on', '>=', $from)->exists()) {
                throw ValidationException::withMessages(['from' => 'Recurring assignments cannot overlap. Choose a later effective range.']);
            }
            $id = DB::table('attendance_recurring_assignments')->insertGetId(['rider_id' => $staff->id, 'policy_id' => $policy->id, 'starts_on' => $from, 'ends_on' => $until, 'authorized_by' => $actor->id, 'created_at' => now()]);
            app(AuditWriter::class)->record($actor, 'attendance.recurring_assigned', (string) $id, ['from' => $from, 'until' => $until], module: 'M10', entityType: 'attendance_recurring_assignment');
        }, 3);
    }

    public function ensure(int $staff, string $from, string $until): void
    {
        DB::transaction(function () use ($staff, $from, $until): void {
            Staff::query()->whereKey($staff)->lockForUpdate()->firstOrFail();
            $existing = AttendanceSchedule::query()->where('rider_id', $staff)->whereBetween('service_date', [$from, $until])->pluck('id', 'service_date');
            $assignments = DB::table('attendance_recurring_assignments')->where('rider_id', $staff)->where('starts_on', '<=', $until)->where('ends_on', '>=', $from)->get();
            foreach (CarbonPeriod::create($from, $until) as $date) {
                $day = $date->toDateString();
                if ($existing->has($day)) {
                    continue;
                }
                $assignment = $assignments->first(fn (object $row): bool => $row->starts_on <= $day && $row->ends_on >= $day);
                if (! $assignment) {
                    continue;
                }
                $policy = AttendancePolicy::query()->findOrFail($assignment->policy_id);
                $kind = DB::table('attendance_policy_holidays')->where('policy_id', $policy->id)->where('service_date', $day)->exists() ? 'public_holiday' : (in_array($date->isoWeekday(), $policy->rules['workdays'], true) ? 'scheduled' : 'day_off');
                $configure = app(ConfigureAttendance::class);
                [$start, $end] = $kind === 'scheduled' ? $configure->times($day, $policy->rules) : [null, null];
                $configure->assertNoOverlap($staff, $start, $end);
                $schedule = new AttendanceSchedule;
                $schedule->forceFill(['rider_id' => $staff, 'policy_id' => $policy->id, 'service_date' => $day, 'day_type' => $kind, 'scheduled_start_at' => $start, 'scheduled_end_at' => $end, 'authorized_by' => $assignment->authorized_by, 'remarks' => 'Recurring assignment '.$assignment->id])->save();
            }
        }, 3);
    }

    public function holiday(User $actor, string $policyId, string $date, string $name): void
    {
        abort_unless(app(LeaveAccess::class)->configure($actor->fresh()), 403);
        Validator::make(compact('policyId', 'date', 'name'), ['policyId' => ['required', 'uuid'], 'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now()->timezone(config('dcms.business_timezone'))->toDateString()], 'name' => ['required', 'string', 'max:100', 'regex:/\S/']])->validate();
        DB::transaction(function () use ($actor, $policyId, $date, $name): void {
            $policy = AttendancePolicy::query()->where('public_id', $policyId)->where('is_active', true)->firstOrFail();
            DB::table('attendance_policy_holidays')->insertOrIgnore(['policy_id' => $policy->id, 'service_date' => $date, 'name' => trim($name), 'authorized_by' => $actor->id, 'created_at' => now()]);
            app(AuditWriter::class)->record($actor, 'attendance.holiday_configured', $policy->public_id, ['service_date' => $date], module: 'M10', entityType: 'attendance_policy');
        });
    }
}
