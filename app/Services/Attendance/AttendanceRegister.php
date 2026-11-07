<?php

namespace App\Services\Attendance;

use App\Models\Staff;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceRegister
{
    public function __construct(private AttendanceAccess $access) {}

    /** @return array{from:string,until:string,status:?string,rider:?string,search:?string} */
    public function criteria(array $input): array
    {
        $today = now()->timezone(config('dcms.business_timezone'));
        $criteria = Validator::make(array_merge(['from' => $today->copy()->startOfMonth()->toDateString(), 'until' => $today->toDateString(), 'status' => '', 'rider' => '', 'search' => ''], $input), [
            'from' => ['required', 'date_format:Y-m-d'], 'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(array_keys(AttendanceStatus::LABELS))], 'rider' => ['nullable', 'uuid'], 'search' => ['nullable', 'string', 'max:100'],
        ])->validate();
        if (CarbonImmutable::parse($criteria['from'])->diffInDays(CarbonImmutable::parse($criteria['until'])) > 366) {
            throw ValidationException::withMessages(['until' => 'Choose a range of up to one year.']);
        }

        return $criteria;
    }

    /** UI33/UI53: union explicit calendar expectations and unlinked raw events, never fake punches. */
    public function query(User $actor, array $criteria, bool $own = false, ?CarbonImmutable $asOf = null): Builder
    {
        $c = $this->criteria($criteria);
        $riders = $own ? Staff::query()->whereKey($actor->staff_id) : $this->access->riders($actor);
        $riders->when($c['rider'] !== '', fn ($q) => $q->where('public_id', $c['rider']));
        if (trim($c['search']) !== '') {
            $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($c['search'])).'%';
            $riders->where(fn ($q) => $q->where('staff_no', 'ilike', $prefix)->orWhereRaw("concat_ws(' ',first_name,last_name) ilike ?", [$prefix]));
        }
        $instant = ($asOf ?? now()->toImmutable())->format('Y-m-d H:i:s.uP');
        $case = "CASE WHEN a.id IS NOT NULL THEN CASE WHEN a.effective_clock_out_at IS NULL THEN CASE WHEN s.day_type='scheduled' AND s.scheduled_end_at>? THEN 'open' WHEN s.day_type='scheduled' THEN 'incomplete' ELSE 'needs_review' END ELSE a.status END WHEN s.day_type<>'scheduled' THEN s.day_type WHEN EXISTS (SELECT 1 FROM leave_request_days ld JOIN leave_requests lr ON lr.id=ld.leave_request_id WHERE ld.schedule_id=s.id AND ld.charged AND lr.status='approved') THEN 'leave' WHEN s.scheduled_end_at<=? THEN 'absent' ELSE 'expected' END";
        $columns = "s.id AS schedule_id,s.public_id AS schedule_public_id,a.id AS attendance_id,a.public_id AS attendance_public_id,st.public_id AS rider_public_id,st.staff_no,concat_ws(' ',st.first_name,st.last_name) AS rider_name,s.service_date,s.day_type,s.scheduled_start_at,s.scheduled_end_at,p.code AS policy_code,p.version AS policy_version,p.rules->>'break_minutes' AS break_minutes,a.raw_clock_in_at,a.raw_clock_out_at,a.effective_clock_in_at,a.effective_clock_out_at,a.worked_minutes,a.is_late,a.late_minutes";
        $scheduled = DB::table('attendance_schedules as s')->join('staff as st', 'st.id', '=', 's.rider_id')->join('attendance_policies as p', 'p.id', '=', 's.policy_id')
            ->leftJoin('attendances as a', fn ($join) => $join->on('a.rider_id', '=', 's.rider_id')->on('a.service_date', '=', 's.service_date')->where(fn ($q) => $q->whereColumn('a.schedule_id', 's.id')->orWhereNull('a.schedule_id')))
            ->whereIn('s.rider_id', (clone $riders)->select('staff.id'))->whereBetween('s.service_date', [$c['from'], $c['until']])->selectRaw($columns)->selectRaw("$case AS result_status", [$instant, $instant])
            ->selectRaw("EXISTS(SELECT 1 FROM attendance_adjustments adj WHERE adj.schedule_id=s.id AND adj.status='pending') AS pending_correction");
        $unscheduled = DB::table('attendances as a')->join('staff as st', 'st.id', '=', 'a.rider_id')->whereNull('a.schedule_id')->whereIn('a.rider_id', (clone $riders)->select('staff.id'))
            ->whereBetween('a.service_date', [$c['from'], $c['until']])->whereNotExists(fn ($q) => $q->selectRaw('1')->from('attendance_schedules as s')->whereColumn('s.rider_id', 'a.rider_id')->whereColumn('s.service_date', 'a.service_date'))
            ->selectRaw("NULL::bigint AS schedule_id,NULL::uuid AS schedule_public_id,a.id AS attendance_id,a.public_id AS attendance_public_id,st.public_id AS rider_public_id,st.staff_no,concat_ws(' ',st.first_name,st.last_name) AS rider_name,a.service_date,'unscheduled' AS day_type,NULL::timestamptz AS scheduled_start_at,NULL::timestamptz AS scheduled_end_at,NULL::varchar AS policy_code,NULL::integer AS policy_version,NULL::text AS break_minutes,a.raw_clock_in_at,a.raw_clock_out_at,a.effective_clock_in_at,a.effective_clock_out_at,a.worked_minutes,a.is_late,a.late_minutes,'needs_review' AS result_status,false AS pending_correction");
        $query = DB::query()->fromSub($scheduled->unionAll($unscheduled), 'attendance_register');
        if ($c['status'] === 'pending_correction') {
            $query->where('pending_correction', true);
        } elseif ($c['status'] === 'late') {
            $query->where('is_late', true);
        } elseif ($c['status'] !== '') {
            $query->where('result_status', $c['status']);
        }

        return $query;
    }
}
