<?php

namespace App\Actions\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
use App\Models\AttendanceSchedule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Models\User;
use App\Notifications\LeaveUpdated;
use App\Services\Attendance\LeaveAccess;
use App\Services\Attendance\LeaveBalance;
use App\Services\Attendance\LeaveDocument;
use App\Services\Attendance\WorkCalendar;
use App\Services\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** M10 / leave_requests, leave_types and leave_request_days: explicit calendar, independent review, no punch mutation. */
class ManageLeave
{
    public function __construct(private LeaveAccess $access, private LeaveDocument $documents, private AuditWriter $audit, private LeaveBalance $balances, private WorkCalendar $workCalendar) {}

    public function duration(User $actor, array $data): int
    {
        abort_unless($this->access->own($actor->fresh()), 403);
        Validator::make($data, ['type' => ['required', 'uuid'], 'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date']])->validate();
        $type = LeaveType::query()->where('public_id', $data['type'])->where('is_active', true)->first()
            ?? throw ValidationException::withMessages(['type' => 'Choose an active leave type.']);

        return count(array_filter($this->calendar($actor->staff_id, $data, $type), fn ($day) => $day['day_type'] === 'scheduled'));
    }

    public function configure(User $actor, array $data, ?string $id = null, ?int $version = null): LeaveType
    {
        return DB::transaction(function () use ($actor, $data, $id, $version): LeaveType {
            $actor = $actor->fresh();
            abort_unless($this->access->configure($actor), 403);
            $data += ['allow_past_dates' => false, 'description' => null, 'tracks_balance' => false];
            $data = Validator::make($data, ['description' => ['nullable', 'string', 'max:2000'], 'tracks_balance' => ['required', 'boolean'], 'name' => ['required', 'string', 'max:100'], 'max_calendar_days' => ['required', 'integer', 'between:1,93'], 'requires_document' => ['required', 'boolean'], 'allow_past_dates' => ['required', 'boolean'], 'allow_pending_cancel' => ['required', 'boolean'], 'is_active' => ['required', 'boolean']])->validate();
            $data['name'] = trim($data['name']);
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['leave.type:'.mb_strtolower($data['name'])]);
            if ($data['name'] === '') {
                throw ValidationException::withMessages(['name' => 'Enter a leave type name.']);
            }
            $type = $id ? LeaveType::query()->where('public_id', $id)->lockForUpdate()->firstOrFail() : new LeaveType;
            if ($id && $type->lock_version !== $version) {
                throw ValidationException::withMessages(['type' => 'This leave type changed. Reload it before saving.']);
            }
            if (LeaveType::query()->whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])->when($id, fn ($q) => $q->where('id', '<>', $type->id))->exists()) {
                throw ValidationException::withMessages(['name' => 'That leave type already exists.']);
            }
            $type->forceFill([...$data, 'configured_by' => $actor->id, 'lock_version' => $id ? $type->lock_version + 1 : 0])->save();
            $this->audit->record($actor, 'leave.type_configured', $type->public_id, ['changed_fields' => array_keys($data)], module: 'M10', entityType: 'leave_type');

            return $type;
        }, 3);
    }

    /** @return array{token:string,total_days:int,type_name:string,type:string,start_date:string,end_date:string,reason:string,excluded_days:int,balances:array,tracks_balance:bool} */
    public function preview(User $actor, array $data, ?UploadedFile $support = null): array
    {
        $actor = $actor->fresh();
        abort_unless($this->access->own($actor), 403);
        $data = $this->validate($data);
        $type = LeaveType::query()->where('public_id', $data['type'])->where('is_active', true)->first()
            ?? throw ValidationException::withMessages(['type' => 'Choose an active leave type.']);
        $this->validateSupport($type, $support);
        $calendar = $this->calendar($actor->staff_id, $data, $type);
        $this->overlap($actor->staff_id, $data);
        $balanceSnapshot = $this->balances->check($actor->staff_id, $type, $calendar);
        $payload = ['actor' => $actor->id, 'staff' => $actor->staff_id, 'data' => $data, 'type' => $type->id, 'type_version' => $type->lock_version, 'calendar' => $calendar, 'file' => $support ? hash_file('sha256', $support->getRealPath()) : null, 'expires' => now()->addMinutes(15)->timestamp, 'key' => (string) Str::uuid7()];

        return ['balances' => $balanceSnapshot, 'tracks_balance' => $type->tracks_balance, 'token' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'total_days' => count(array_filter($calendar, fn ($day) => $day['day_type'] === 'scheduled')), 'type_name' => $type->name, ...$data, 'excluded_days' => count(array_filter($calendar, fn ($day) => $day['day_type'] !== 'scheduled'))];
    }

    public function submit(User $actor, array $data, string $token, ?UploadedFile $support = null): LeaveRequest
    {
        try {
            $preview = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['review' => 'Review the application again before submitting.']);
        }
        $key = 'leave-support/'.Str::uuid7().'.encrypted';
        try {
            return DB::transaction(function () use ($actor, $data, $preview, $support, $key): LeaveRequest {
                $actor = $actor->fresh();
                abort_unless($this->access->own($actor), 403);
                Staff::query()->whereKey($actor->staff_id)->lockForUpdate()->firstOrFail();
                $data = $this->validate($data);
                if (($preview['actor'] ?? null) !== $actor->id || ($preview['staff'] ?? null) !== $actor->staff_id || ($preview['data'] ?? null) !== $data || ($preview['expires'] ?? 0) < now()->timestamp || ($preview['file'] ?? null) !== ($support ? hash_file('sha256', $support->getRealPath()) : null)) {
                    throw ValidationException::withMessages(['review' => 'The review expired or your application changed. Review it again.']);
                }
                $existing = LeaveRequest::query()->where('request_key', $preview['key'])->first();
                if ($existing) {
                    return $existing;
                }
                $type = LeaveType::query()->whereKey($preview['type'])->where('is_active', true)->lockForUpdate()->first()
                    ?? throw ValidationException::withMessages(['type' => 'This leave type is no longer available. Choose an active leave type and review again.']);
                $calendar = $this->calendar($actor->staff_id, $data, $type, true);
                if ($type->lock_version !== $preview['type_version'] || $calendar !== $preview['calendar']) {
                    throw ValidationException::withMessages(['review' => 'The leave policy or calendar changed. Review the application again.']);
                }
                $this->overlap($actor->staff_id, $data);
                $this->validateSupport($type, $support);
                $balanceSnapshot = $this->balances->check($actor->staff_id, $type, $calendar);
                $doc = $support ? $this->documents->store($actor, $support, $key) : null;
                $request = new LeaveRequest;
                $request->forceFill(['request_key' => $preview['key'], 'rider_id' => $actor->staff_id, 'requested_by' => $actor->id, 'leave_type_id' => $type->id, 'start_date' => $data['start_date'], 'end_date' => $data['end_date'], 'total_days' => count(array_filter($calendar, fn ($day) => $day['day_type'] === 'scheduled')), 'reason' => $data['reason'], 'type_snapshot' => ['tracks_balance' => $type->tracks_balance, 'balance_snapshot' => $balanceSnapshot, 'name' => $type->name, 'max_calendar_days' => $type->max_calendar_days, 'allow_past_dates' => $type->allow_past_dates, 'allow_pending_cancel' => $type->allow_pending_cancel, 'requires_document' => $type->requires_document, 'version' => $type->lock_version], 'calendar_snapshot' => $calendar, 'supporting_document_id' => $doc?->id, 'status' => 'pending', 'submitted_at' => now()])->save();
                foreach ($calendar as $day) {
                    $request->schedules()->attach($day['id'], ['service_date' => $day['service_date'], 'charged' => $day['day_type'] === 'scheduled']);
                }
                $this->audit->record($actor, 'leave.submitted', $request->public_id, ['status' => 'pending'], module: 'M10', entityType: 'leave_request');
                foreach (User::permission('attendance.approve_correction')->get() as $reviewer) {
                    if ($reviewer->id !== $actor->id && $reviewer->staff_id !== $actor->staff_id && $this->access->review($reviewer) && $this->access->view($reviewer, $request)) {
                        $reviewer->notify(new LeaveUpdated($request->public_id, 'pending', true));
                    }
                }

                return $request;
            }, 3);
        } catch (\Throwable $error) {
            if ($support) {
                try {
                    Storage::disk('s3')->delete($key);
                } catch (\Throwable) {
                }
            }
            throw $error;
        }
    }

    public function decide(User $actor, LeaveRequest $target, string $decision, string $remarks, int $version): LeaveRequest
    {
        return DB::transaction(function () use ($actor, $target, $decision, $remarks, $version): LeaveRequest {
            Staff::query()->whereKey($target->rider_id)->lockForUpdate()->firstOrFail();
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($target->id);
            $actor = $actor->fresh();
            abort_unless($this->access->review($actor) && $this->access->view($actor, $request), 403);
            if ($actor->id === $request->requested_by || $actor->staff_id === $request->rider_id) {
                throw ValidationException::withMessages(['decision' => 'Another authorized administrator must review your own leave.']);
            }
            Validator::make(compact('decision', 'remarks'), ['decision' => ['required', 'in:approved,rejected'], 'remarks' => [$decision === 'rejected' ? 'required' : 'nullable', 'string', 'max:5000']])->validate();
            $remarks = trim($remarks);
            if ($decision === 'rejected' && $remarks === '') {
                throw ValidationException::withMessages(['remarks' => 'Enter a rejection reason.']);
            }
            $this->pending($request, $version);
            if ($decision === 'approved') {
                $data = ['start_date' => $request->start_date->toDateString(), 'end_date' => $request->end_date->toDateString()];
                $calendar = $this->schedules($request->rider_id, $data, true);
                if ($calendar != $request->calendar_snapshot) {
                    throw ValidationException::withMessages(['decision' => 'The attendance calendar changed after submission. Reject this request and ask the Rider to apply again.']);
                }
                $this->overlap($request->rider_id, $data, $request->id);
                $start = CarbonImmutable::parse($data['start_date'], config('dcms.business_timezone'))->startOfDay()->utc();
                $end = CarbonImmutable::parse($data['end_date'], config('dcms.business_timezone'))->addDay()->startOfDay()->utc();
                if (Attendance::query()->where('rider_id', $request->rider_id)->where(function ($q) use ($data, $start, $end): void {
                    $q->whereBetween('service_date', [$data['start_date'], $data['end_date']])->orWhere(fn ($overlap) => $overlap->where('raw_clock_in_at', '<', $end)->where(fn ($out) => $out->whereNull('effective_clock_out_at')->orWhere('effective_clock_out_at', '>', $start)));
                })->exists()) {
                    throw ValidationException::withMessages(['decision' => 'Recorded attendance overlaps these dates. Resolve the attendance conflict before approving.']);
                }
                if (AttendanceAdjustment::query()->whereIn('schedule_id', array_column($calendar, 'id'))->where('status', 'pending')->exists()) {
                    throw ValidationException::withMessages(['decision' => 'A calendar correction is pending. Resolve it before reviewing leave.']);
                }
            }
            $request->forceFill(['status' => $decision, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'reviewer_remarks' => $remarks !== '' ? $remarks : null, 'lock_version' => $request->lock_version + 1])->save();
            $this->audit->record($actor, 'leave.'.$decision, $request->public_id, ['status' => $decision], module: 'M10', entityType: 'leave_request');
            $request->requester->notify(new LeaveUpdated($request->public_id, $decision));

            return $request;
        }, 3);
    }

    public function cancel(User $actor, LeaveRequest $target, int $version): LeaveRequest
    {
        return DB::transaction(function () use ($actor, $target, $version): LeaveRequest {
            Staff::query()->whereKey($target->rider_id)->lockForUpdate()->firstOrFail();
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($target->id);
            $actor = $actor->fresh();
            abort_unless($this->access->own($actor) && $actor->staff_id === $request->rider_id && $actor->id === $request->requested_by, 403);
            $this->pending($request, $version);
            if (! ($request->type_snapshot['allow_pending_cancel'] ?? false)) {
                throw ValidationException::withMessages(['request' => 'This leave policy does not allow cancellation.']);
            }
            $request->forceFill(['status' => 'cancelled', 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'lock_version' => $request->lock_version + 1])->save();
            $this->audit->record($actor, 'leave.cancelled', $request->public_id, ['status' => 'cancelled'], module: 'M10', entityType: 'leave_request');
            $actor->notify(new LeaveUpdated($request->public_id, 'cancelled'));

            return $request;
        }, 3);
    }

    private function pending(LeaveRequest $request, int $version): void
    {
        if ($request->status !== 'pending' || $request->lock_version !== $version) {
            throw ValidationException::withMessages(['decision' => 'This request was already decided or changed. Refresh to see its current status.']);
        }
    }

    private function validate(array $data): array
    {
        $data = Validator::make($data, ['type' => ['required', 'uuid'], 'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'], 'reason' => ['required', 'string', 'max:5000']])->validate();
        $data['reason'] = trim($data['reason']);
        if ($data['reason'] === '') {
            throw ValidationException::withMessages(['reason' => 'Enter your reason for leave.']);
        }

        return $data;
    }

    private function validateSupport(LeaveType $type, ?UploadedFile $support): void
    {
        Validator::make(['support' => $support], ['support' => [$type->requires_document ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']])->validate();
    }

    private function calendar(int $rider, array $data, LeaveType $type, bool $lock = false): array
    {
        $days = CarbonImmutable::parse($data['start_date'])->diffInDays(CarbonImmutable::parse($data['end_date'])) + 1;
        if ($days > $type->max_calendar_days || (! $type->allow_past_dates && $data['start_date'] < now()->timezone(config('dcms.business_timezone'))->toDateString())) {
            throw ValidationException::withMessages(['start_date' => 'The requested dates are outside this leave type’s allowed date rules or maximum calendar span.']);
        }
        $this->workCalendar->ensure($rider, $data['start_date'], $data['end_date']);
        $calendar = $this->schedules($rider, $data, $lock);
        if (count($calendar) !== (int) $days || array_filter($calendar, fn ($day) => ! in_array($day['day_type'], ['scheduled', 'day_off', 'public_holiday'], true))) {
            throw ValidationException::withMessages(['start_date' => 'Working calendar unavailable for one or more dates. Ask your administrator to open Attendance > Schedules & policies and assign a recurring work schedule covering these dates.']);
        }
        if (! array_filter($calendar, fn ($day) => $day['day_type'] === 'scheduled')) {
            throw ValidationException::withMessages(['start_date' => 'The selected range contains no scheduled working days.']);
        }

        return $calendar;
    }

    private function schedules(int $rider, array $data, bool $lock = false): array
    {
        $query = AttendanceSchedule::query()->where('rider_id', $rider)->whereBetween('service_date', [$data['start_date'], $data['end_date']])->orderBy('service_date');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->map(fn ($s) => ['id' => $s->id, 'service_date' => $s->service_date->toDateString(), 'day_type' => $s->day_type, 'version' => $s->lock_version, 'policy_id' => $s->policy_id, 'start' => $s->scheduled_start_at?->toISOString(), 'end' => $s->scheduled_end_at?->toISOString()])->all();
    }

    private function overlap(int $rider, array $data, ?int $except = null): void
    {
        if (LeaveRequest::query()->where('rider_id', $rider)->whereIn('status', ['pending', 'approved'])->when($except, fn ($q) => $q->where('id', '<>', $except))->where('start_date', '<=', $data['end_date'])->where('end_date', '>=', $data['start_date'])->exists()) {
            throw ValidationException::withMessages(['start_date' => 'This range overlaps an existing pending or approved leave application.']);
        }
    }
}
