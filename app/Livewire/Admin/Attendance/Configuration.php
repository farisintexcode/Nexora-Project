<?php

namespace App\Livewire\Admin\Attendance;

use App\Actions\Attendance\ConfigureAttendance;
use App\Models\Attendance;
use App\Models\AttendancePolicy;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\WorkCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** M10/UI59: publish immutable attendance rule versions and recurring work schedules. */
#[Layout('layouts.admin')]
class Configuration extends Component
{
    public string $code = 'standard';

    public array $rules = [];

    public string $riderId = '';

    public string $policyId = '';

    public string $from = '';

    public string $until = '';

    public string $holidayDate = '';

    public string $holidayName = '';

    public function mount(): void
    {
        Gate::authorize('configure', Attendance::class);
        $this->rules = config('attendance.initial_rules');
        $this->from = now()->timezone(config('dcms.business_timezone'))->toDateString();
        $this->until = $this->from;
    }

    public function publish(ConfigureAttendance $configure): void
    {
        $policy = $configure->policy(auth()->user(), $this->only(['code', 'rules']));
        $this->policyId = $policy->public_id;
        session()->flash('status', 'Policy version '.$policy->version.' published. Existing schedules keep their pinned version.');
    }

    public function assignRecurring(WorkCalendar $calendar): void
    {
        $calendar->assign(auth()->user(), $this->riderId, $this->policyId, $this->from, $this->until);
        session()->flash('status', 'Recurring work schedule assigned. Existing date overrides are preserved.');
    }

    public function addHoliday(WorkCalendar $calendar): void
    {
        $calendar->holiday(auth()->user(), $this->policyId, $this->holidayDate, $this->holidayName);
        session()->flash('status', 'Public holiday configured. Existing dates require an independent correction.');
    }

    public function render(AttendanceAccess $access): View
    {
        Gate::authorize('configure', Attendance::class);

        return view('livewire.admin.attendance.configuration', ['recurringAssignments' => DB::table('attendance_recurring_assignments as a')->join('staff as s', 's.id', '=', 'a.rider_id')->join('attendance_policies as p', 'p.id', '=', 'a.policy_id')->whereIn('s.id', $access->riders(auth()->user())->select('id'))->select('a.*', 's.staff_no', 'p.code', 'p.version')->orderByDesc('a.id')->limit(50)->get(), 'policies' => AttendancePolicy::query()->where('is_active', true)->orderBy('code')->orderByDesc('version')->get(), 'riders' => $access->riders(auth()->user())->with('user')->where('is_active', true)->orderBy('staff_no')->limit(200)->get()]);
    }
}
