<?php

namespace App\Livewire\Admin\Attendance;

use App\Actions\Attendance\ConfigureAttendance;
use App\Models\Attendance;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** M10/UI59: publish immutable attendance rule versions. */
#[Layout('layouts.admin')]
class Configuration extends Component
{
    public string $code = 'standard';

    public array $rules = [];

    public function mount(): void
    {
        Gate::authorize('configure', Attendance::class);
        $this->rules = config('attendance.initial_rules');
    }

    public function publish(ConfigureAttendance $configure): void
    {
        $policy = $configure->policy(auth()->user(), $this->only(['code', 'rules']));
        session()->flash('status', 'Policy version '.$policy->version.' published. Existing schedules keep their pinned version.');
    }

    public function render(): View
    {
        Gate::authorize('configure', Attendance::class);

        return view('livewire.admin.attendance.configuration');
    }
}
