<?php

namespace App\Livewire\Admin\Attendance;

use App\Actions\Attendance\CorrectAttendance;
use App\Models\Attendance;
use App\Models\AttendanceSchedule;
use App\Services\Attendance\AttendanceAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** M10/UI59 original/effective values, immutable correction decisions. */
#[Layout('layouts.admin')]
class Show extends Component
{
    #[Locked]
    public int $scheduleId;

    #[Locked]
    public array $before = [];

    public string $clockIn = '';

    public string $clockOut = '';

    public string $scheduledStart = '';

    public string $scheduledEnd = '';

    public string $dayType = 'scheduled';

    public string $reason = '';

    public string $reviewRemarks = '';

    public function mount(string $schedule, AttendanceAccess $access, CorrectAttendance $correct): void
    {
        Gate::authorize('viewAny', Attendance::class);
        $record = AttendanceSchedule::query()->where('public_id', $schedule)->firstOrFail();
        abort_unless($access->visible(auth()->user(), $record->rider_id), 404);
        $this->scheduleId = $record->id;
        $this->loadOriginal($correct);
    }

    public function loadOriginal(CorrectAttendance $correct): void
    {
        $schedule = $this->record();
        $attendance = $this->punch($schedule);
        $this->before = $correct->snapshot($schedule, $attendance);
        $this->dayType = $attendance ? 'scheduled' : $schedule->day_type;
        $this->scheduledStart = $schedule->scheduled_start_at?->timezone(config('dcms.business_timezone'))->format('Y-m-d\TH:i') ?? '';
        $this->scheduledEnd = $schedule->scheduled_end_at?->timezone(config('dcms.business_timezone'))->format('Y-m-d\TH:i') ?? '';
        $tz = config('dcms.business_timezone');
        $this->clockIn = $attendance?->effective_clock_in_at?->timezone($tz)->format('Y-m-d\\TH:i') ?? '';
        $this->clockOut = $attendance?->effective_clock_out_at?->timezone($tz)->format('Y-m-d\\TH:i') ?? '';
    }

    public function requestCorrection(CorrectAttendance $correct): void
    {
        $this->validate(['clockIn' => ['required']], attributes: ['clockIn' => 'requested clock in']);
        $data = ['reason' => $this->reason, 'day_type' => $this->dayType, 'clock_in' => $this->clockIn, 'clock_out' => $this->clockOut];
        if (auth()->user()->can('attendance.configure')) {
            $data += ['scheduled_start' => $this->scheduledStart, 'scheduled_end' => $this->scheduledEnd];
        }
        $correct->request(auth()->user(), $this->record(), $data, $this->before);
        $this->reason = '';
        session()->flash('status', 'Correction requested. Effective attendance stays unchanged until independent approval.');
    }

    public function decide(string $id, string $decision, CorrectAttendance $correct): void
    {
        abort_unless(Str::isUuid($id), 404);
        $schedule = $this->record();
        $request = $schedule->adjustments()->where('public_id', $id)->firstOrFail();
        $correct->review(auth()->user(), $request, $decision, $this->reviewRemarks);
        $this->reviewRemarks = '';
        $this->loadOriginal($correct);
        session()->flash('status', 'Correction '.$decision.'. Original punches are unchanged.');
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

    public function render(CorrectAttendance $correct): View
    {
        Gate::authorize('viewAny', Attendance::class);
        $schedule = $this->record();

        return view('livewire.admin.attendance.show', ['schedule' => $schedule, 'attendance' => $this->punch($schedule), 'requests' => $schedule->adjustments()->with(['requester', 'reviewer'])->orderByDesc('id')->get(), 'canRequest' => $correct->canRequest(auth()->user(), $schedule)]);
    }
}
