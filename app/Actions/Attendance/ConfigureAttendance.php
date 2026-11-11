<?php

namespace App\Actions\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePolicy;
use App\Models\AttendanceSchedule;
use App\Models\User;
use App\Services\Attendance\AttendanceAccess;
use App\Services\AuditWriter;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ConfigureAttendance
{
    public function __construct(private AttendanceAccess $access, private AuditWriter $audit) {}

    public function policy(User $actor, array $data): AttendancePolicy
    {
        return DB::transaction(function () use ($actor, $data): AttendancePolicy {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            Gate::forUser($actor)->authorize('configure', Attendance::class);
            $data = Validator::make($data, [
                'code' => ['required', 'string', 'alpha_dash:ascii', 'max:64'],
                'rules' => ['required', 'array:workdays,shift_start,shift_end,break_minutes,grace_minutes,present_minutes,half_day_minutes,overnight_allowed'],
                'rules.workdays' => ['required', 'array', 'min:1', 'max:7'], 'rules.workdays.*' => ['required', 'integer', 'between:1,7', 'distinct'],
                'rules.shift_start' => ['required', 'date_format:H:i'], 'rules.shift_end' => ['required', 'date_format:H:i'],
                'rules.break_minutes' => ['required', 'integer', 'between:0,720'], 'rules.grace_minutes' => ['required', 'integer', 'between:0,240'],
                'rules.present_minutes' => ['required', 'integer', 'between:1,1440'], 'rules.half_day_minutes' => ['required', 'integer', 'min:1', 'lt:rules.present_minutes'],
                'rules.overnight_allowed' => ['required', 'boolean'],
                'is_default' => ['sometimes', 'boolean'],
                'effective_from' => ['nullable', 'required_if_accepted:is_default', 'date_format:Y-m-d', 'after_or_equal:'.now()->timezone(config('dcms.business_timezone'))->toDateString()],
            ], ['effective_from.after_or_equal' => 'Choose today or a later start date. Days that already started keep their version.'])->validate();
            $isDefault = (bool) ($data['is_default'] ?? false);
            $rules = $data['rules'];
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['attendance.policy:'.$data['code']]);
            foreach (['break_minutes', 'grace_minutes', 'present_minutes', 'half_day_minutes'] as $key) {
                $rules[$key] = (int) $rules[$key];
            }
            $rules['workdays'] = array_map('intval', $rules['workdays']);
            $rules['overnight_allowed'] = (bool) $rules['overnight_allowed'];
            [$start,$end] = $this->times('2000-01-03', $rules);
            if ($end->diffInMinutes($start, true) - $rules['break_minutes'] < $rules['present_minutes']) {
                throw ValidationException::withMessages(['rules.present_minutes' => 'Present minutes cannot exceed the configured shift less unpaid break.']);
            }
            $policy = new AttendancePolicy;
            $policy->forceFill(['code' => $data['code'], 'version' => 1 + (int) AttendancePolicy::query()->where('code', $data['code'])->max('version'), 'timezone' => config('dcms.business_timezone'), 'rules' => $rules, 'is_active' => true, 'approved_by' => $actor->id, 'approved_at' => now(), 'is_default' => $isDefault, 'effective_from' => $isDefault ? $data['effective_from'] : null])->save();
            $this->audit->record($actor, 'attendance.policy_published', $policy->public_id, ['status' => 'approved', 'is_default' => $isDefault, 'effective_from' => $policy->effective_from?->toDateString()], module: 'M10', entityType: 'attendance_policy');

            return $policy;
        }, 3);
    }

    /** Explicit calendar assignments, atomic and bounded; existing dates are never overwritten. */
    public function assign(User $actor, string $riderId, string $policyId, string $from, string $until, string $dayType = 'calendar', ?string $remarks = null): int
    {
        Gate::forUser($actor->fresh())->authorize('configure', Attendance::class);
        Validator::make(compact('riderId', 'policyId'), ['riderId' => ['required', 'uuid'], 'policyId' => ['required', 'uuid']])->validate();
        Validator::make(compact('from', 'until', 'dayType', 'remarks'), ['from' => ['required', 'date_format:Y-m-d'], 'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'dayType' => ['required', 'in:calendar,scheduled,leave,day_off,public_holiday'], 'remarks' => ['nullable', 'string', 'max:2000']])->validate();
        $today = now()->timezone(config('dcms.business_timezone'))->toDateString();
        if ($from < $today || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($until)) > 92) {
            throw ValidationException::withMessages(['from' => 'Assign today or future dates, up to 93 days at a time. Historical changes require a correction.']);
        }

        return DB::transaction(function () use ($actor, $riderId, $policyId, $from, $until, $dayType, $remarks): int {
            $rider = $this->access->riders($actor)->where('public_id', $riderId)->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            Gate::forUser($actor)->authorize('configure', Attendance::class);
            abort_unless($this->access->visible($actor, $rider->id), 404);
            if (! $rider->is_active || ! $rider->user?->isActive()) {
                throw ValidationException::withMessages(['riderId' => 'Choose an active Rider with an active account.']);
            }
            $policy = AttendancePolicy::query()->where('public_id', $policyId)->where('is_active', true)->firstOrFail();
            if (AttendanceSchedule::query()->where('rider_id', $rider->id)->whereBetween('service_date', [$from, $until])->exists()) {
                throw ValidationException::withMessages(['from' => 'One or more dates already have a schedule. Use an independent correction to change them.']);
            }
            $count = 0;
            foreach (CarbonPeriod::create($from, $until) as $date) {
                $kind = $dayType === 'calendar' ? (in_array($date->isoWeekday(), $policy->rules['workdays'], true) ? 'scheduled' : 'day_off') : $dayType;
                [$start,$end] = $kind === 'scheduled' ? $this->times($date->toDateString(), $policy->rules) : [null, null];
                $this->assertNoOverlap($rider->id, $start, $end);
                $schedule = new AttendanceSchedule;
                $schedule->forceFill(['rider_id' => $rider->id, 'policy_id' => $policy->id, 'service_date' => $date->toDateString(), 'day_type' => $kind, 'scheduled_start_at' => $start, 'scheduled_end_at' => $end, 'authorized_by' => $actor->id, 'remarks' => $remarks])->save();
                $this->audit->record($actor, 'attendance.schedule_assigned', $schedule->public_id, ['status' => $kind], module: 'M10', entityType: 'attendance_schedule');
                $count++;
            }

            return $count;
        }, 3);
    }

    public function times(string $date, array $rules): array
    {
        $tz = config('dcms.business_timezone');
        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$rules['shift_start'], $tz);
        $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$rules['shift_end'], $tz);
        if ($end->lessThanOrEqualTo($start)) {
            if (! $rules['overnight_allowed'] || $rules['shift_start'] === $rules['shift_end']) {
                throw ValidationException::withMessages(['rules.shift_end' => 'End must follow start; overnight work must be explicitly allowed and shorter than 24 hours.']);
            }
            $end = $end->addDay();
        }

        return [$start->utc(), $end->utc()];
    }

    public function assertNoOverlap(int $rider, ?CarbonImmutable $start, ?CarbonImmutable $end, ?int $except = null): void
    {
        if ($start !== null && AttendanceSchedule::query()->where('rider_id', $rider)->where('day_type', 'scheduled')->when($except !== null, fn ($q) => $q->where('id', '<>', $except))->where('scheduled_start_at', '<', $end)->where('scheduled_end_at', '>', $start)->exists()) {
            throw ValidationException::withMessages(['from' => 'The proposed shift overlaps another scheduled shift.']);
        }
    }
}
