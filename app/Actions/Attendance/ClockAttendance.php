<?php

namespace App\Actions\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceSchedule;
use App\Models\LeaveRequest;
use App\Models\Staff;
use App\Models\User;
use App\Services\Attendance\AttendanceStatus;
use App\Services\Attendance\WorkCalendar;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ClockAttendance
{
    public function __construct(private AttendanceStatus $status, private AuditWriter $audit, private WorkCalendar $calendar) {}

    /** M10/UI53: lock the existing Rider, never accept a client-supplied clock time. */
    public function punch(User $actor, string $direction, string $requestId, ?string $record = null, ?int $version = null, ?string $remarks = null): Attendance
    {
        Validator::make(compact('direction', 'requestId', 'remarks'), ['direction' => ['required', 'in:in,out'], 'requestId' => ['required', 'uuid'], 'remarks' => ['nullable', 'string', 'max:2000']])->validate();

        return DB::transaction(function () use ($actor, $direction, $requestId, $record, $version, $remarks): Attendance {
            Gate::forUser($actor->fresh())->authorize('clock', Attendance::class);
            $rider = Staff::query()->lockForUpdate()->findOrFail($actor->staff_id);
            $actor = $actor->fresh();
            Gate::forUser($actor)->authorize('clock', Attendance::class);
            if ($actor->staff_id !== $rider->id) {
                throw ValidationException::withMessages(['clock' => 'Your staff identity changed. Refresh attendance before clocking.']);
            }
            $prior = Attendance::query()->where('clock_'.$direction.'_request_id', $requestId)->first();
            if ($prior !== null) {
                abort_unless($prior->rider_id === $rider->id && ($direction === 'in' || $prior->public_id === $record), 403);

                return $prior;
            }
            $now = now()->toImmutable();
            $open = Attendance::query()->where('rider_id', $rider->id)->openShift()->lockForUpdate()->first();
            if ($direction === 'out') {
                $todayDate = $now->timezone(config('dcms.business_timezone'))->toDateString();
                $onLeaveToday = LeaveRequest::query()->where('rider_id', $rider->id)->where('status', 'approved')->where('start_date', '<=', $todayDate)->where('end_date', '>=', $todayDate)->exists();
                $supersededByLeave = $open === null && $record !== null && Attendance::query()->where('rider_id', $rider->id)->where('public_id', $record)->whereNull('effective_clock_out_at')->exists();
                if ($onLeaveToday || $supersededByLeave) {
                    throw ValidationException::withMessages(['clock' => 'You are on approved leave. Clocking out is not available; ask an administrator to correct the shift if needed.']);
                }
                if ($open === null || $open->public_id !== $record || $open->lock_version !== $version) {
                    throw ValidationException::withMessages(['clock' => 'This shift has changed or is already closed. Refresh attendance before retrying.']);
                }
                if ($now->lessThanOrEqualTo($open->effective_clock_in_at) || $now->lessThanOrEqualTo($open->raw_clock_in_at)) {
                    throw ValidationException::withMessages(['clock' => 'Clock out must be after clock in. Please retry in a moment.']);
                }
                $open->load('schedule.policy');
                $open->forceFill([...$this->status->calculate($open->schedule, $open->effective_clock_in_at, $now), 'raw_clock_out_at' => $now, 'effective_clock_out_at' => $now, 'clock_out_request_id' => $requestId, 'lock_version' => $open->lock_version + 1])->save();
                $this->audit->record($actor, 'attendance.clock_out', $open->public_id, ['status' => $open->status], module: 'M10', entityType: 'attendance');

                return $open;
            }
            if ($open !== null) {
                throw ValidationException::withMessages(['clock' => 'You already have an open shift. Clock out or request a correction first.']);
            }
            $today = $now->timezone(config('dcms.business_timezone'))->startOfDay();
            try {
                $this->calendar->ensure($rider->id, $today->subDay()->toDateString(), $today->toDateString());
            } catch (ValidationException) {
                // A conflicting shift leaves the day unscheduled; the punch is still recorded for review.
            }
            $overnight = AttendanceSchedule::query()->where('rider_id', $rider->id)->where('day_type', 'scheduled')->where('service_date', $today->subDay()->toDateString())
                ->where('scheduled_start_at', '<=', $now)->where('scheduled_end_at', '>', $now)->first();
            $schedule = $overnight ?? AttendanceSchedule::query()->where('rider_id', $rider->id)->where('service_date', $today->toDateString())->where('day_type', 'scheduled')->first();
            $date = $schedule?->service_date->toDateString() ?? $today->toDateString();
            $through = $schedule?->scheduled_end_at?->subMicrosecond()->timezone(config('dcms.business_timezone'))->toDateString() ?? $date;
            if (LeaveRequest::query()->where('rider_id', $rider->id)->where('status', 'approved')->where('start_date', '<=', $through)->where('end_date', '>=', $date)->exists()) {
                throw ValidationException::withMessages(['clock' => 'Approved leave covers this shift. You cannot clock in during approved leave.']);
            }
            $exists = Attendance::query()->where('rider_id', $rider->id)->where(fn ($q) => $schedule !== null ? $q->where('schedule_id', $schedule->id)->orWhere(fn ($q) => $q->whereNull('schedule_id')->where('service_date', $date)) : $q->where('service_date', $date))->exists();
            if ($exists) {
                throw ValidationException::withMessages(['clock' => 'Attendance already exists for this service date. Use a correction request instead.']);
            }
            $attendance = new Attendance;
            $attendance->forceFill([...$this->status->calculate($schedule, $now, null), 'rider_id' => $rider->id, 'schedule_id' => $schedule?->id, 'service_date' => $date, 'raw_clock_in_at' => $now, 'effective_clock_in_at' => $now, 'clock_in_request_id' => $requestId, 'remarks' => $remarks, 'lock_version' => 0])->save();
            $this->audit->record($actor, 'attendance.clock_in', $attendance->public_id, ['status' => $attendance->status], module: 'M10', entityType: 'attendance');

            return $attendance;
        }, 3);
    }
}
