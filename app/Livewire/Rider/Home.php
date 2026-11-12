<?php

namespace App\Livewire\Rider;

use App\Models\Attendance;
use App\Models\Task;
use App\Notifications\TaskReviewed;
use App\Notifications\TasksAssigned;
use App\Services\Tasks\RiderAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.rider')]
class Home extends Component
{
    public function render(): View
    {
        Gate::authorize('use-rider-interface');
        $scope = app(RiderAccess::class)->scope(Task::query(), auth()->user());
        if (! auth()->user()->can('tasks.view')) {
            $scope->whereRaw('false');
        }
        $counts = [
            'pending' => (clone $scope)->whereIn('work_status', ['unstarted', 'in_progress'])->count(),
            'returned' => (clone $scope)->where('review_status', 'returned')->count(),
            'submitted' => (clone $scope)->where('work_status', 'submitted')->count(),
            'completed' => (clone $scope)->where('work_status', 'completed')->count(),
        ];
        $next = (clone $scope)->whereIn('work_status', ['unstarted', 'in_progress'])->with(['customer', 'batch'])->orderByRaw('COALESCE(scheduled_visit_at,due_at) ASC NULLS LAST')->orderBy('id')->first();

        $assignmentsCount = (clone $scope)->distinct()->count('assignment_batch_id');

        $notices = auth()->user()->notifications()->whereIn('type', [TaskReviewed::class, TasksAssigned::class])->latest()->limit(5)->get();

        $openAttendance = Attendance::query()->where('rider_id', auth()->user()->staff_id)->openShift()->first();
        $latestAttendance = Attendance::query()->where('rider_id', auth()->user()->staff_id)->latest('service_date')->latest('id')->first();

        return view('livewire.rider.home', compact('counts', 'next', 'notices', 'openAttendance', 'latestAttendance', 'assignmentsCount'));
    }
}
