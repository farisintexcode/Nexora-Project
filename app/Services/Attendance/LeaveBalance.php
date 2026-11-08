<?php

namespace App\Services\Attendance;

use App\Models\LeaveType;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** M10/UI59: yearly entitlement, immutable adjustments and canonical charged request days. */
class LeaveBalance
{
    /** @return array{entitlement:?int,adjustments:int,used:int,pending:int,available:?int} */
    public function forStaff(int $staff, LeaveType $type, int $year): array
    {
        $entitlement = DB::table('leave_entitlements')->where('leave_type_id', $type->id)->where('leave_year', $year)->value('days');
        $adjustments = (int) DB::table('leave_balance_adjustments')->where('rider_id', $staff)->where('leave_type_id', $type->id)->where('leave_year', $year)->sum('days');
        $usage = DB::table('leave_request_days as d')->join('leave_requests as r', 'r.id', '=', 'd.leave_request_id')
            ->where('r.rider_id', $staff)->where('r.leave_type_id', $type->id)->where('d.charged', true)
            ->whereBetween('d.service_date', ["$year-01-01", "$year-12-31"])->whereIn('r.status', ['pending', 'approved'])
            ->whereRaw("r.type_snapshot->>'tracks_balance' = 'true'")
            ->selectRaw('r.status,count(*) as days')->groupBy('r.status')->pluck('days', 'status');
        $used = (int) ($usage['approved'] ?? 0);
        $pending = (int) ($usage['pending'] ?? 0);

        return ['entitlement' => $entitlement === null ? null : (int) $entitlement, 'adjustments' => $adjustments, 'used' => $used, 'pending' => $pending, 'available' => $entitlement === null ? null : (int) $entitlement + $adjustments - $used - $pending];
    }

    /**
     * Submission holds the staff and type locks until all yearly reservations commit.
     *
     * @param  array<int, array{id:int,service_date:string,day_type:string,version:int,policy_id:int,start:?string,end:?string}>  $calendar
     * @return array<int, array{entitlement:?int,adjustments:int,used:int,pending:int,available:?int,requested:int}>
     */
    public function check(int $staff, LeaveType $type, array $calendar): array
    {
        if (! $type->tracks_balance) {
            return [];
        }
        $years = collect($calendar)->where('day_type', 'scheduled')->groupBy(fn (array $day): string => substr($day['service_date'], 0, 4));
        $snapshots = [];
        foreach ($years as $year => $days) {
            $balance = $this->forStaff($staff, $type, (int) $year);
            if ($balance['available'] === null || $balance['available'] < $days->count()) {
                throw ValidationException::withMessages(['type' => "Insufficient leave balance or annual entitlement not configured for $year. Contact your administrator."]);
            }
            $snapshots[$year] = [...$balance, 'requested' => $days->count()];
        }

        return $snapshots;
    }

    public function entitlement(User $actor, LeaveType $type, int $year, int $days): void
    {
        abort_unless(app(LeaveAccess::class)->configure($actor->fresh()), 403);
        Validator::make(compact('year', 'days'), ['year' => ['required', 'integer', 'between:2000,2200', 'min:'.now()->timezone(config('dcms.business_timezone'))->year], 'days' => ['required', 'integer', 'between:0,366']])->validate();
        DB::transaction(function () use ($actor, $type, $year, $days): void {
            $type = LeaveType::query()->whereKey($type->id)->lockForUpdate()->firstOrFail();
            $before = DB::table('leave_entitlements')->where('leave_type_id', $type->id)->where('leave_year', $year)->value('days');
            DB::table('leave_entitlements')->upsert([['leave_type_id' => $type->id, 'leave_year' => $year, 'days' => $days, 'configured_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]], ['leave_type_id', 'leave_year'], ['days', 'configured_by', 'updated_at']);
            app(AuditWriter::class)->record($actor, 'leave.entitlement_changed', $type->public_id, ['leave_year' => $year, 'before_days' => $before, 'days' => $days], module: 'M10', entityType: 'leave_type');
        }, 3);
    }

    public function adjust(User $actor, string $staffId, LeaveType $type, int $year, int $days, string $reason): void
    {
        abort_unless(app(LeaveAccess::class)->configure($actor->fresh()), 403);
        Validator::make(compact('staffId', 'year', 'days', 'reason'), ['staffId' => ['required', 'uuid'], 'year' => ['required', 'integer', 'between:2000,2200', 'min:'.now()->timezone(config('dcms.business_timezone'))->year], 'days' => ['required', 'integer', 'between:-366,366', 'not_in:0'], 'reason' => ['required', 'string', 'max:1000', 'regex:/\S/']])->validate();
        DB::transaction(function () use ($actor, $staffId, $type, $year, $days, $reason): void {
            $staff = app(AttendanceAccess::class)->riders($actor)->where('public_id', $staffId)->lockForUpdate()->firstOrFail();
            $type = LeaveType::query()->whereKey($type->id)->lockForUpdate()->firstOrFail();
            $balance = $this->forStaff($staff->id, $type, $year);
            if (! $type->tracks_balance || $balance['available'] === null || $balance['available'] + $days < 0) {
                throw ValidationException::withMessages(['adjustmentDays' => 'Configure a tracked entitlement and preserve existing reservations before adjusting.']);
            }
            $id = DB::table('leave_balance_adjustments')->insertGetId(['rider_id' => $staff->id, 'leave_type_id' => $type->id, 'leave_year' => $year, 'days' => $days, 'reason' => encrypt(trim($reason)), 'authorized_by' => $actor->id, 'created_at' => now()]);
            app(AuditWriter::class)->record($actor, 'leave.balance_adjusted', (string) $id, ['leave_year' => $year, 'days' => $days], module: 'M10', entityType: 'leave_balance_adjustment');
        }, 3);
    }
}
