<?php

namespace App\Services\Staff;

use App\Models\Attendance;
use App\Models\Staff;
use App\Services\Attendance\AttendanceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staff profile view of a Rider's attendance: one row per day the Rider clocked in or was on approved leave.
 * Approved leave supersedes a clock-in on the same day, as it does for open shifts.
 */
class StaffAttendanceHistory
{
    /** @var array<string, string> */
    public const TONES = ['present' => 'success', 'late' => 'warning', 'half_day' => 'warning', 'incomplete' => 'warning', 'open' => 'brand', 'leave' => 'brand', 'needs_review' => 'neutral'];

    /**
     * Days between the two dates (inclusive), newest first.
     *
     * @return Collection<int, array{date: CarbonImmutable, clock_in: ?CarbonImmutable, clock_out: ?CarbonImmutable, status: string, label: string, tone: string}>
     */
    public function days(Staff $rider, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $today = now()->timezone(config('dcms.business_timezone'))->toDateString();
        $leave = $this->approvedLeave($rider, $start, $end);

        $worked = Attendance::query()
            ->where('rider_id', $rider->id)
            ->whereBetween('service_date', [$start->toDateString(), $end->toDateString()])
            ->get(['service_date', 'raw_clock_in_at', 'effective_clock_in_at', 'raw_clock_out_at', 'effective_clock_out_at', 'status', 'is_late', 'late_minutes'])
            ->reject(fn (Attendance $record): bool => $leave->has($record->service_date->toDateString()))
            ->map(function (Attendance $record) use ($today): array {
                $clockOut = $record->effective_clock_out_at ?? $record->raw_clock_out_at;
                $status = match (true) {
                    $clockOut === null => $record->service_date->toDateString() === $today ? 'open' : 'incomplete',
                    $record->is_late => 'late',
                    in_array($record->status, ['half_day', 'incomplete', 'needs_review'], true) => $record->status,
                    default => 'present',
                };
                $label = AttendanceStatus::LABELS[$status];
                if ($status === 'late' && $record->late_minutes > 0) {
                    $label .= ' · '.$record->late_minutes.' min';
                }

                return ['date' => $record->service_date, 'clock_in' => $record->raw_clock_in_at ?? $record->effective_clock_in_at, 'clock_out' => $clockOut, 'status' => $status, 'label' => $label, 'tone' => self::TONES[$status]];
            });

        $onLeave = $leave->map(fn (string $type, string $date): array => [
            'date' => CarbonImmutable::parse($date), 'clock_in' => null, 'clock_out' => null, 'status' => 'leave', 'label' => $type, 'tone' => self::TONES['leave'],
        ]);

        return $worked->concat($onLeave->values())->sortByDesc(fn (array $day): string => $day['date']->toDateString())->values();
    }

    /**
     * Working days covered by approved leave, keyed by date, with the leave type name.
     *
     * @return Collection<string, string>
     */
    private function approvedLeave(Staff $rider, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return DB::table('leave_request_days as day')
            ->join('leave_requests as request', 'request.id', '=', 'day.leave_request_id')
            ->leftJoin('leave_types as type', 'type.id', '=', 'request.leave_type_id')
            ->where('request.rider_id', $rider->id)
            ->where('request.status', 'approved')
            ->where('day.charged', true)
            ->whereBetween('day.service_date', [$start->toDateString(), $end->toDateString()])
            ->get(['day.service_date', 'type.name', 'request.type_snapshot'])
            ->mapWithKeys(fn (object $row): array => [
                CarbonImmutable::parse($row->service_date)->toDateString() => $row->name ?? (json_decode((string) $row->type_snapshot, true)['name'] ?? 'Leave'),
            ]);
    }
}
