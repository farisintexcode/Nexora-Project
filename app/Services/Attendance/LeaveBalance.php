<?php

namespace App\Services\Attendance;

use App\Models\LeaveType;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** M10/UI59: the type's yearly limit, immutable adjustments and canonical charged request days. */
class LeaveBalance
{
    /**
     * Yearly balance in the leave type's unit: working days charged from the calendar, or applications
     * (each pending or approved request counts once, in the year it starts). The same yearly limit applies to every year.
     *
     * @return array{unit: string, entitlement: ?int, adjustments: int, used: int, pending: int, available: ?int}
     */
    public function forStaff(int $staff, LeaveType $type, int $year): array
    {
        $entitlement = $type->days_per_year;
        $adjustments = (int) DB::table('leave_balance_adjustments')->where('rider_id', $staff)->where('leave_type_id', $type->id)->where('leave_year', $year)->sum('days');
        $requests = DB::table('leave_requests as r')->where('r.rider_id', $staff)->where('r.leave_type_id', $type->id)
            ->whereIn('r.status', ['pending', 'approved'])->whereRaw("r.type_snapshot->>'tracks_balance' = 'true'");
        $usage = $type->limitsApplications()
            ? $requests->whereBetween('r.start_date', ["$year-01-01", "$year-12-31"])->selectRaw('r.status,count(*) as total')->groupBy('r.status')->pluck('total', 'status')
            : $requests->join('leave_request_days as d', 'd.leave_request_id', '=', 'r.id')->where('d.charged', true)
                ->whereBetween('d.service_date', ["$year-01-01", "$year-12-31"])->selectRaw('r.status,count(*) as total')->groupBy('r.status')->pluck('total', 'status');
        $used = (int) ($usage['approved'] ?? 0);
        $pending = (int) ($usage['pending'] ?? 0);

        return ['unit' => $type->limit_unit ?? 'days', 'entitlement' => $entitlement === null ? null : (int) $entitlement, 'adjustments' => $adjustments, 'used' => $used, 'pending' => $pending, 'available' => $entitlement === null ? null : (int) $entitlement + $adjustments - $used - $pending];
    }

    /**
     * Submission holds the staff and type locks until all yearly reservations commit.
     *
     * @param  array<int, array{id:int,service_date:string,day_type:string,version:int,policy_id:int,start:?string,end:?string}>  $calendar
     * @return array<int, array{unit: string, entitlement:?int,adjustments:int,used:int,pending:int,available:?int,requested:int}>
     */
    public function check(int $staff, LeaveType $type, array $calendar): array
    {
        if (! $type->tracks_balance) {
            return [];
        }
        $requested = $type->limitsApplications()
            ? [substr((string) collect($calendar)->min('service_date'), 0, 4) => 1]
            : collect($calendar)->where('day_type', 'scheduled')->groupBy(fn (array $day): string => substr($day['service_date'], 0, 4))->map->count()->all();
        $snapshots = [];
        foreach ($requested as $year => $count) {
            $balance = $this->forStaff($staff, $type, (int) $year);
            if ($balance['available'] === null) {
                throw ValidationException::withMessages(['type' => "{$type->name} has no maximum leave days per year set yet. Ask your administrator to set it under Leave Types."]);
            }
            if ($balance['available'] < $count) {
                $limit = $balance['entitlement'] + $balance['adjustments'];
                throw ValidationException::withMessages(['type' => $type->limitsApplications()
                    ? "You have already used all $limit {$type->name} applications for $year (including requests waiting for approval)."
                    : "Not enough {$type->name} left for $year: this request needs $count working ".($count === 1 ? 'day' : 'days').", but only {$balance['available']} ".($balance['available'] === 1 ? 'is' : 'are').' left.']);
            }
            $snapshots[$year] = [...$balance, 'requested' => $count];
        }

        return $snapshots;
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
                throw ValidationException::withMessages(['adjustmentDays' => 'Set the maximum leave days per year first, and keep enough days for requests already reserved.']);
            }
            $id = DB::table('leave_balance_adjustments')->insertGetId(['rider_id' => $staff->id, 'leave_type_id' => $type->id, 'leave_year' => $year, 'days' => $days, 'reason' => encrypt(trim($reason)), 'authorized_by' => $actor->id, 'created_at' => now()]);
            app(AuditWriter::class)->record($actor, 'leave.balance_adjusted', (string) $id, ['leave_year' => $year, 'days' => $days], module: 'M10', entityType: 'leave_balance_adjustment');
        }, 3);
    }
}
