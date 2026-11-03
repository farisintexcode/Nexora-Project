<?php

namespace App\Livewire\Rider;

use App\Actions\Attendance\ClockAttendance;
use App\Actions\Attendance\CorrectAttendance;
use App\Models\Attendance as Punch;
use App\Models\AttendanceSchedule;
use App\Notifications\AttendanceCorrected;
use App\Services\Attendance\AttendanceRegister;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/** M10/UI53: own server punches and personal history, mobile first. */
#[Layout('layouts.rider')]
class Attendance extends Component
{
    use WithPagination;

    #[Locked]
    public bool $historyPage = false;

    #[Locked]
    public string $inRequest;

    #[Locked]
    public string $outRequest;

    #[Locked]
    public ?string $openId = null;

    #[Locked]
    public ?int $openVersion = null;

    #[Locked]
    public ?int $correctionSchedule = null;

    #[Locked]
    public array $before = [];

    public string $from = '';

    public string $until = '';

    public string $remarks = '';

    public string $clockIn = '';

    public string $clockOut = '';

    public string $reason = '';

    public function mount(): void
    {
        $this->authorizeOwn();
        $this->historyPage = request()->routeIs('rider.attendance.history');
        $this->inRequest = (string) Str::uuid7();
        $this->outRequest = (string) Str::uuid7();
        $today = now()->timezone(config('dcms.business_timezone'));
        $this->until = $today->toDateString();
        $this->from = $today->startOfMonth()->toDateString();
        $this->syncOpen();
    }

    private function authorizeOwn(): void
    {
        abort_unless(auth()->user()->isActive() && auth()->user()->staff_id !== null && auth()->user()->can('attendance.view_own'), 403);
    }

    private function syncOpen(): void
    {
        $open = Punch::query()->where('rider_id', auth()->user()->staff_id)->whereNull('effective_clock_out_at')->first();
        $this->openId = $open?->public_id;
        $this->openVersion = $open?->lock_version;
    }

    public function clock(string $direction, ClockAttendance $clock): void
    {
        $this->authorizeOwn();
        $punch = $clock->punch(auth()->user(), $direction, $direction === 'in' ? $this->inRequest : $this->outRequest, $this->openId, $this->openVersion, $this->remarks);
        $this->syncOpen();
        $this->remarks = '';
        session()->flash('status', 'Clock '.($direction === 'in' ? 'in' : 'out').' saved at '.($direction === 'in' ? $punch->raw_clock_in_at : $punch->raw_clock_out_at)->timezone(config('dcms.business_timezone'))->format('d M Y H:i:s').' MYT.');
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedUntil(): void
    {
        $this->resetPage();
    }

    public function selectCorrection(string $id, CorrectAttendance $correct): void
    {
        $this->authorizeOwn();
        abort_unless(Str::isUuid($id), 404);
        $schedule = AttendanceSchedule::query()->where('public_id', $id)->where('rider_id', auth()->user()->staff_id)->firstOrFail();
        abort_unless($correct->canRequest(auth()->user(), $schedule), 403);
        $attendance = Punch::query()->where('rider_id', $schedule->rider_id)->where('service_date', $schedule->service_date->toDateString())->first();
        abort_unless($attendance !== null, 422);
        $this->correctionSchedule = $schedule->id;
        $this->before = $correct->snapshot($schedule, $attendance);
        $tz = config('dcms.business_timezone');
        $this->clockIn = $attendance->effective_clock_in_at->timezone($tz)->format('Y-m-d\TH:i');
        $this->clockOut = $attendance->effective_clock_out_at?->timezone($tz)->format('Y-m-d\TH:i') ?? '';
        $this->reason = '';
    }

    public function requestCorrection(CorrectAttendance $correct): void
    {
        $this->authorizeOwn();
        $schedule = AttendanceSchedule::query()->whereKey($this->correctionSchedule)->where('rider_id', auth()->user()->staff_id)->firstOrFail();
        $correct->request(auth()->user(), $schedule, ['reason' => $this->reason, 'day_type' => 'scheduled', 'clock_in' => $this->clockIn, 'clock_out' => $this->clockOut], $this->before);
        $this->correctionSchedule = null;
        session()->flash('status', 'Correction requested. Your original punches remain unchanged.');
    }

    public function render(AttendanceRegister $register): View
    {
        $this->authorizeOwn();
        $error = null;
        try {
            $query = $register->query(auth()->user(), $this->only(['from', 'until']), true);
        } catch (ValidationException $e) {
            $error = $e->validator->errors()->first();
            $query = $register->query(auth()->user(), [], true)->whereRaw('false');
        }
        $open = Punch::query()->with('schedule.policy')->where('rider_id', auth()->user()->staff_id)->whereNull('effective_clock_out_at')->first();
        $today = now()->timezone(config('dcms.business_timezone'))->toDateString();
        $todaySchedule = AttendanceSchedule::query()->with('policy')->where('rider_id', auth()->user()->staff_id)->where('service_date', $today)->first();
        $todayPunch = Punch::query()->where('rider_id', auth()->user()->staff_id)->where('service_date', $today)->first();

        return view($this->historyPage ? 'livewire.rider.attendance-history' : 'livewire.rider.attendance', ['open' => $open, 'todaySchedule' => $todaySchedule, 'todayPunch' => $todayPunch, 'rows' => $query->orderByDesc('service_date')->orderByDesc('attendance_id')->paginate(15), 'filterError' => $error, 'notices' => auth()->user()->notifications()->where('type', AttendanceCorrected::class)->latest()->limit(5)->get()])->layout(auth()->user()->can('admin.access') ? 'layouts.admin' : 'layouts.rider');
    }
}
