<?php

namespace App\Services\Attendance;

use App\Models\AttendanceSchedule;
use Carbon\CarbonImmutable;

/** Module.md#m10-approved-policy; pure calculation over pinned schedule rules. */
class AttendanceStatus
{
    public const LABELS = ['present' => 'Present', 'late' => 'Late', 'half_day' => 'Half Day', 'absent' => 'Absent', 'leave' => 'Leave', 'day_off' => 'Rest Day', 'public_holiday' => 'Public Holiday', 'incomplete' => 'Incomplete', 'open' => 'Open', 'expected' => 'Expected', 'needs_review' => 'Needs review', 'cancelled' => 'Deactivated'];

    /** @return array{status:string,worked_minutes:?int,is_late:bool,late_minutes:int} */
    public function calculate(?AttendanceSchedule $schedule, CarbonImmutable $in, ?CarbonImmutable $out): array
    {
        $result = ['status' => 'needs_review', 'worked_minutes' => null, 'is_late' => false, 'late_minutes' => 0];
        if ($schedule === null || $schedule->day_type !== 'scheduled') {
            return $result;
        }
        $rules = $schedule->policy->rules;
        $late = $in->greaterThan($schedule->scheduled_start_at->addMinutes($rules['grace_minutes']));
        $result['is_late'] = $late;
        $result['late_minutes'] = $late ? (int) floor($schedule->scheduled_start_at->diffInSeconds($in) / 60) : 0;
        $result['status'] = 'incomplete';
        if ($out !== null) {
            $minutes = max(0, (int) floor($in->diffInSeconds($out) / 60) - $rules['break_minutes']);
            $result['worked_minutes'] = $minutes;
            $result['status'] = $minutes >= $rules['present_minutes'] ? 'present' : ($minutes >= $rules['half_day_minutes'] ? 'half_day' : 'incomplete');
        }

        return $result;
    }
}
