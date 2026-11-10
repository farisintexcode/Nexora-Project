<?php

namespace App\Actions\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
use App\Models\AttendanceSchedule;
use App\Models\LeaveRequest;
use App\Models\Staff;
use App\Models\User;
use App\Notifications\AttendanceCorrected;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\AttendanceStatus;
use App\Services\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CorrectAttendance
{
    public function __construct(private AttendanceAccess $access, private AttendanceStatus $status, private ConfigureAttendance $configuration, private AuditWriter $audit) {}

    public function canRequest(User $actor, AttendanceSchedule $schedule): bool
    {
        return $actor->isActive() && $actor->can('attendance.correct') && (($actor->staff_id === $schedule->rider_id && $actor->can('attendance.view_own')) || $this->access->visible($actor, $schedule->rider_id));
    }

    /** @return array{schedule_version:int,attendance_version:?int,attendance_id:?string,day_type:string,scheduled_start_at:?string,scheduled_end_at:?string,schedule_id:?int,effective_clock_in_at:?string,effective_clock_out_at:?string,status:?string} */
    public function snapshot(AttendanceSchedule $schedule, ?Attendance $attendance): array
    {
        return ['schedule_version' => $schedule->lock_version, 'attendance_version' => $attendance?->lock_version, 'attendance_id' => $attendance?->public_id,
            'day_type' => $schedule->day_type, 'scheduled_start_at' => $schedule->scheduled_start_at?->toISOString(), 'scheduled_end_at' => $schedule->scheduled_end_at?->toISOString(),
            'schedule_id' => $attendance?->schedule_id, 'effective_clock_in_at' => $attendance?->effective_clock_in_at?->toISOString(), 'effective_clock_out_at' => $attendance?->effective_clock_out_at?->toISOString(), 'status' => $attendance?->status];
    }

    public function request(User $actor, AttendanceSchedule $target, array $data, array $before): AttendanceAdjustment
    {
        return DB::transaction(function () use ($actor, $target, $data, $before): AttendanceAdjustment {
            Staff::query()->whereKey($target->rider_id)->lockForUpdate()->firstOrFail();
            $schedule = AttendanceSchedule::query()->lockForUpdate()->findOrFail($target->id);
            $actor = $actor->fresh();
            abort_unless($this->canRequest($actor, $schedule), 403);
            $this->guardApprovedLeave($schedule);
            $attendance = $this->attendance($schedule);
            if (! $this->sameSnapshot($this->snapshot($schedule, $attendance), $before)) {
                throw ValidationException::withMessages(['correction' => 'Attendance changed. Reload the original values before requesting a correction.']);
            }
            $data = Validator::make($data, ['reason' => ['required', 'string', 'max:5000'], 'day_type' => ['required', 'in:scheduled,leave,day_off,public_holiday,cancelled'],
                'clock_in' => ['nullable', 'date_format:Y-m-d\TH:i'], 'clock_out' => ['nullable', 'date_format:Y-m-d\TH:i'],
                'scheduled_start' => ['nullable', 'date_format:Y-m-d\TH:i', 'required_with:scheduled_end'], 'scheduled_end' => ['nullable', 'date_format:Y-m-d\TH:i', 'required_with:scheduled_start']])->validate();
            $reason = trim($data['reason']);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'A correction reason is required.']);
            }
            $proposed = ['day_type' => $data['day_type']];
            [$start,$end] = $this->proposedSchedule($actor, $schedule, $data);
            $proposed += ['scheduled_start_at' => $start?->toISOString(), 'scheduled_end_at' => $end?->toISOString()];
            if ($attendance !== null) {
                if ($data['day_type'] !== 'scheduled') {
                    throw ValidationException::withMessages(['day_type' => 'A recorded punch must retain its working schedule. Correct effective times instead.']);
                }
                $in = $this->parseTime($data['clock_in'] ?? null);
                $out = $this->parseTime($data['clock_out'] ?? null);
                if ($in === null || $in->timezone(config('dcms.business_timezone'))->toDateString() !== $schedule->service_date->toDateString() || ($out !== null && $out->lessThanOrEqualTo($in))) {
                    throw ValidationException::withMessages(['clock_in' => 'Use the service date for clock in and a later clock out.']);
                }
                if ($out === null && Attendance::query()->where('rider_id', $schedule->rider_id)->where('id', '<>', $attendance->id)->whereNull('effective_clock_out_at')->exists()) {
                    throw ValidationException::withMessages(['clock_out' => 'Another shift is open. Close it before requesting an open interval.']);
                }
                $proposed += ['effective_clock_in_at' => $in->toISOString(), 'effective_clock_out_at' => $out?->toISOString()];
            } elseif (($data['clock_in'] ?? '') !== '' || ($data['clock_out'] ?? '') !== '') {
                throw ValidationException::withMessages(['correction' => 'Calendar-only corrections cannot fabricate raw clock events.']);
            }
            $pending = $schedule->adjustments()->where('status', 'pending')->first();
            if ($pending !== null) {
                if ($pending->requested_by === $actor->id && $pending->proposed_values == $proposed && $pending->reason === $reason) {
                    return $pending;
                }
                throw ValidationException::withMessages(['correction' => 'A correction is already pending for this service date.']);
            }
            $adjustment = new AttendanceAdjustment;
            $adjustment->forceFill(['schedule_id' => $schedule->id, 'attendance_id' => $attendance?->id, 'requested_by' => $actor->id, 'requested_at' => now(), 'before_values' => $before, 'proposed_values' => $proposed, 'reason' => $reason, 'status' => 'pending'])->save();
            $this->audit->record($actor, 'attendance.correction_requested', $adjustment->public_id, ['status' => 'pending'], module: 'M10', entityType: 'attendance_adjustment');

            return $adjustment;
        }, 3);
    }

    public function review(User $actor, AttendanceAdjustment $target, string $decision, ?string $remarks = null): AttendanceAdjustment
    {
        Validator::make(compact('decision', 'remarks'), ['decision' => ['required', 'in:approved,rejected'], 'remarks' => ['required', 'string', 'max:5000']])->validate();
        if (trim((string) $remarks) === '') {
            throw ValidationException::withMessages(['reviewRemarks' => 'Review remarks are required.']);
        }

        return DB::transaction(function () use ($actor, $target, $decision, $remarks): AttendanceAdjustment {
            $context = AttendanceSchedule::query()->findOrFail($target->schedule_id);
            Staff::query()->whereKey($context->rider_id)->lockForUpdate()->firstOrFail();
            $schedule = AttendanceSchedule::query()->lockForUpdate()->findOrFail($context->id);
            $attendance = $this->attendance($schedule);
            $adjustment = AttendanceAdjustment::query()->lockForUpdate()->findOrFail($target->id);
            $actor = $actor->fresh();
            abort_unless($this->access->visible($actor, $schedule->rider_id) && $actor->can('attendance.approve_correction') && $actor->id !== $adjustment->requested_by && $actor->staff_id !== $schedule->rider_id, 403);
            if ($adjustment->status !== 'pending') {
                if ($adjustment->status === $decision && $adjustment->reviewed_by === $actor->id) {
                    return $adjustment;
                }
                throw ValidationException::withMessages(['correction' => 'This correction has already been decided.']);
            }
            if ($decision === 'approved' && ! $this->sameSnapshot($this->snapshot($schedule, $attendance), $adjustment->before_values)) {
                throw ValidationException::withMessages(['correction' => 'Attendance changed after this request. Reject it and request the current values.']);
            }
            $proposed = $adjustment->proposed_values;
            if ($decision === 'approved') {
                $this->guardApprovedLeave($schedule);
                if ($attendance !== null) {
                    $in = CarbonImmutable::parse($proposed['effective_clock_in_at']);
                    $out = isset($proposed['effective_clock_out_at']) ? CarbonImmutable::parse($proposed['effective_clock_out_at']) : null;
                    if ($out === null && Attendance::query()->where('rider_id', $schedule->rider_id)->where('id', '<>', $attendance->id)->whereNull('effective_clock_out_at')->exists()) {
                        throw ValidationException::withMessages(['correction' => 'Another shift is now open. Reject and request a closed interval.']);
                    }
                    if ($in->isFuture() || $out?->isFuture()) {
                        throw ValidationException::withMessages(['correction' => 'Future attendance cannot be approved.']);
                    }
                } else {
                    $this->configuration->assertNoOverlap($schedule->rider_id, isset($proposed['scheduled_start_at']) ? CarbonImmutable::parse($proposed['scheduled_start_at']) : null, isset($proposed['scheduled_end_at']) ? CarbonImmutable::parse($proposed['scheduled_end_at']) : null, $schedule->id);
                }
            }
            $adjustment->forceFill(['status' => $decision, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_remarks' => trim((string) $remarks)])->save();
            if ($decision === 'approved') {
                $start = isset($proposed['scheduled_start_at']) ? CarbonImmutable::parse($proposed['scheduled_start_at']) : null;
                $end = isset($proposed['scheduled_end_at']) ? CarbonImmutable::parse($proposed['scheduled_end_at']) : null;
                $this->configuration->assertNoOverlap($schedule->rider_id, $start, $end, $schedule->id);
                if ($schedule->day_type !== $proposed['day_type'] || $schedule->scheduled_start_at?->toISOString() !== $start?->toISOString() || $schedule->scheduled_end_at?->toISOString() !== $end?->toISOString()) {
                    $schedule->forceFill(['day_type' => $proposed['day_type'], 'scheduled_start_at' => $start, 'scheduled_end_at' => $end, 'lock_version' => $schedule->lock_version + 1])->save();
                }
                if ($attendance !== null) {
                    $attendance->forceFill([...$this->status->calculate($schedule, $in, $out), 'schedule_id' => $schedule->id, 'effective_clock_in_at' => $in, 'effective_clock_out_at' => $out, 'lock_version' => $attendance->lock_version + 1])->save();
                }
            }
            $this->audit->record($actor, 'attendance.correction_'.$decision, $adjustment->public_id, ['status' => $decision], module: 'M10', entityType: 'attendance_adjustment');
            $recipient = $schedule->rider->user;
            if ($recipient !== null) {
                $recipient->notify(new AttendanceCorrected($adjustment->public_id, $decision));
            }

            return $adjustment;
        }, 3);
    }

    private function guardApprovedLeave(AttendanceSchedule $schedule): void
    {
        if (LeaveRequest::query()->where('status', 'approved')->whereHas('schedules', fn ($q) => $q->where('attendance_schedules.id', $schedule->id))->exists()) {
            throw ValidationException::withMessages(['correction' => 'Approved leave covers this calendar date. Its attendance calendar cannot be changed.']);
        }
    }

    private function attendance(AttendanceSchedule $schedule): ?Attendance
    {
        return Attendance::query()->where('rider_id', $schedule->rider_id)->where('service_date', $schedule->service_date->toDateString())->where(fn ($q) => $q->where('schedule_id', $schedule->id)->orWhereNull('schedule_id'))->lockForUpdate()->first();
    }

    private function parseTime(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $time = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, config('dcms.business_timezone'))->utc();
        if ($time->isFuture()) {
            throw ValidationException::withMessages(['clock_in' => 'Future attendance cannot be requested.']);
        }

        return $time;
    }

    private function proposedSchedule(User $actor, AttendanceSchedule $schedule, array $data): array
    {
        if ($data['day_type'] !== 'scheduled') {
            return [null, null];
        }
        $tz = config('dcms.business_timezone');
        [$start,$end] = $schedule->day_type === 'scheduled' ? [$schedule->scheduled_start_at, $schedule->scheduled_end_at] : $this->configuration->times($schedule->service_date->toDateString(), $schedule->policy->rules);
        if (! empty($data['scheduled_start'])) {
            abort_unless($this->access->visible($actor, $schedule->rider_id) && $actor->can('attendance.configure'), 403);
            $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['scheduled_start'], $tz)->utc();
            $end = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['scheduled_end'], $tz)->utc();
            if ($start->timezone($tz)->toDateString() !== $schedule->service_date->toDateString() || $end->lessThanOrEqualTo($start) || $start->diffInHours($end) >= 24 || (! $schedule->policy->rules['overnight_allowed'] && $start->timezone($tz)->toDateString() !== $end->timezone($tz)->toDateString())) {
                throw ValidationException::withMessages(['scheduled_start' => 'Use the service date and a later end within 24 hours, with overnight permission if required.']);
            }
        }
        $this->configuration->assertNoOverlap($schedule->rider_id, $start, $end, $schedule->id);

        return [$start, $end];
    }

    private function sameSnapshot(array $left, array $right): bool
    {
        ksort($left);
        ksort($right);

        return $left === $right;
    }
}
