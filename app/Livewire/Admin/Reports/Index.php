<?php

namespace App\Livewire\Admin\Reports;

use App\Actions\Reports\ManageReports;
use App\Models\AssignmentBatch;
use App\Models\ExportJob;
use App\Models\Staff;
use App\Services\Reports\TaskReports;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public array $filters = ['family' => 'task', 'from' => '', 'until' => '', 'batch' => '', 'rider' => '', 'status' => '', 'task_id' => '', 'columns' => TaskReports::DEFAULT_COLUMNS];

    public bool $previewed = false;

    public function mount(TaskReports $reports): void
    {
        $reports->authorize(auth()->user());
    }

    public function updatedFilters(): void
    {
        $this->previewed = false;
        $this->resetPage();
    }

    public function preview(TaskReports $reports): void
    {
        $reports->criteria($this->filters);
        $this->previewed = true;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->filters = ['family' => 'task', 'from' => '', 'until' => '', 'batch' => '', 'rider' => '', 'status' => '', 'task_id' => '', 'columns' => TaskReports::DEFAULT_COLUMNS];
        $this->previewed = false;
        $this->resetPage();
    }

    public function requestExport(string $kind, ManageReports $reports): void
    {
        $reports->request(auth()->user(), $this->filters, $kind);
        session()->flash('status', 'Your frozen report is queued. The exports worker will prepare the private file.');
    }

    public function retry(string $id, ManageReports $reports): void
    {
        abort_unless(Str::isUuid($id), 404);
        $job = ExportJob::query()->where('requested_by', auth()->id())->where('public_id', $id)->firstOrFail();
        $reports->retry(auth()->user(), $job);
        session()->flash('status', 'Report generation has been queued again.');
    }

    public function downloadUrl(ExportJob $job): string
    {
        return URL::temporarySignedRoute('admin.reports.download', now()->addMinutes(5), ['exportJob' => $job->public_id, 'user' => auth()->id()]);
    }

    public function render(TaskReports $reports): View
    {
        $reports->authorize(auth()->user());
        $snapshot = $this->previewed ? $reports->snapshot(auth()->user(), $this->filters) : null;
        $rows = $snapshot ? new LengthAwarePaginator(array_slice($snapshot['rows'], ($this->getPage() - 1) * 20, 20), count($snapshot['rows']), 20, $this->getPage(), ['path' => request()->url()]) : null;
        $batches = AssignmentBatch::query()->latest('id')->limit(200)->get();
        $riders = Staff::query()->where('is_rider', true)->orderBy('first_name')->limit(200)->get();
        $exports = ExportJob::query()->where('requested_by', auth()->id())->latest('id')->paginate(10, ['*'], 'exportsPage');

        return view('livewire.admin.reports.index', compact('snapshot', 'rows', 'batches', 'riders', 'exports'));
    }
}
