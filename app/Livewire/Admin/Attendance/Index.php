<?php

namespace App\Livewire\Admin\Attendance;

use App\Models\Attendance;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\AttendanceRegister;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** M10/UI33, Database.md schedules/punches/adjustments, Plan.md attendance. */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $rider = '';

    public string $search = '';

    public function mount(): void
    {
        $this->search = (string) session('attendance.filters.'.auth()->id().'.search', '');
        $this->from = $this->from ?: now()->timezone(config('dcms.business_timezone'))->startOfMonth()->toDateString();
        $this->until = $this->until ?: now()->timezone(config('dcms.business_timezone'))->toDateString();
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('status', 'rider', 'search');
        $this->from = now()->timezone(config('dcms.business_timezone'))->startOfMonth()->toDateString();
        $this->until = now()->timezone(config('dcms.business_timezone'))->toDateString();
        $this->resetPage();
    }

    public function filters(): array
    {
        return $this->only(['from', 'until', 'status', 'rider', 'search']);
    }

    public function selectStatus(string $status): void
    {
        $this->status = $status;
        $this->resetPage();
    }

    public function render(AttendanceRegister $register, AttendanceAccess $access): View
    {
        Gate::authorize('viewAny', Attendance::class);
        $asOf = now()->toImmutable();
        $error = null;
        try {
            $query = $register->query(auth()->user(), $this->filters(), asOf: $asOf);
            session()->put('attendance.filters.'.auth()->id(), $this->filters());
        } catch (ValidationException $e) {
            $error = $e->validator->errors()->first();
            $query = $register->query(auth()->user(), [])->whereRaw('false');
        }
        $totals = (clone $query)->selectRaw('result_status, count(*) as total')->groupBy('result_status')->pluck('total', 'result_status')->all();
        $rows = $query->orderByDesc('service_date')->orderBy('staff_no')->orderBy('rider_public_id')->paginate(25);

        return view('livewire.admin.attendance.index', ['rows' => $rows, 'totals' => $totals, 'filterError' => $error, 'asOf' => $asOf, 'riders' => $access->riders(auth()->user())->orderBy('staff_no')->limit(200)->get()]);
    }
}
