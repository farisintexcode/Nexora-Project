<?php

namespace App\Livewire\Admin\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceSchedule;
use App\Models\LeaveRequest;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\AttendanceRegister;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** M10/UI59 scheduled, raw and effective values for one rider day. */
#[Layout('layouts.admin')]
class Show extends Component
{
    #[Locked]
    public int $scheduleId;

    public function mount(string $schedule, AttendanceAccess $access): void
    {
        Gate::authorize('viewAny', Attendance::class);
        $record = AttendanceSchedule::query()->where('public_id', $schedule)->firstOrFail();
        abort_unless($access->visible(auth()->user(), $record->rider_id), 404);
        $this->scheduleId = $record->id;
    }

    private function record(): AttendanceSchedule
    {
        $schedule = AttendanceSchedule::query()->with(['policy', 'rider.user'])->findOrFail($this->scheduleId);
        abort_unless(app(AttendanceAccess::class)->visible(auth()->user(), $schedule->rider_id), 404);

        return $schedule;
    }

    private function punch(AttendanceSchedule $schedule): ?Attendance
    {
        return Attendance::query()->where('rider_id', $schedule->rider_id)->where('service_date', $schedule->service_date->toDateString())->first();
    }

    /** Format minutes as "7h 45m" for quick reading. */
    public static function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        return $minutes < 60 ? $minutes.'m' : intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }

    /**
     * Explain in one sentence why the day has its result status.
     *
     * @param  array{break_minutes?:int,present_minutes?:int,half_day_minutes?:int}  $rules
     */
    private function explanation(string $status, ?Attendance $attendance, array $rules): string
    {
        $worked = self::duration($attendance?->worked_minutes);
        $present = self::duration($rules['present_minutes'] ?? null);
        $halfDay = self::duration($rules['half_day_minutes'] ?? null);

        return match ($status) {
            'present' => "Worked {$worked} after the unpaid break, meeting the Present minimum of {$present}.",
            'half_day' => "Worked {$worked}: below the Present minimum of {$present} but at least the Half Day minimum of {$halfDay}.",
            'incomplete' => $attendance?->effective_clock_out_at === null ? 'Clocked in but no clock out was recorded before the shift ended.' : "Worked {$worked}: below the Half Day minimum of {$halfDay}.",
            'open' => 'Clocked in and the shift has not ended yet.',
            'absent' => 'No clock in was recorded for this working day.',
            'expected' => 'Working day that has not finished yet; no clock in recorded so far.',
            'leave' => 'Covered by approved leave; no attendance expected.',
            'day_off', 'public_holiday' => 'Not a working day; no attendance expected.',
            'cancelled' => 'This schedule was deactivated; history is retained.',
            default => 'This punch is not linked to a working schedule for this day.',
        };
    }

    public function render(AttendanceRegister $register): View
    {
        Gate::authorize('viewAny', Attendance::class);
        $schedule = $this->record();
        $attendance = $this->punch($schedule);
        $date = $schedule->service_date->toDateString();
        $row = $register->query(auth()->user(), ['from' => $date, 'until' => $date, 'rider' => $schedule->rider->public_id])->where('schedule_public_id', $schedule->public_id)->first();
        $status = $row->result_status ?? ($attendance->status ?? $schedule->day_type);
        $leave = LeaveRequest::query()->whereIn('status', ['pending', 'approved'])
            ->whereHas('schedules', fn ($query) => $query->whereKey($schedule->id))->latest('id')->first();

        return view('livewire.admin.attendance.show', [
            'schedule' => $schedule,
            'attendance' => $attendance,
            'status' => $status,
            'rules' => $schedule->policy->rules,
            'explanation' => $this->explanation($status, $attendance, $schedule->policy->rules),
            'leave' => $leave,
            'leaveCharged' => $leave ? (bool) DB::table('leave_request_days')->where('leave_request_id', $leave->id)->where('schedule_id', $schedule->id)->value('charged') : false,
        ]);
    }
}
