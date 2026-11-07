<?php

namespace App\Livewire\Admin\Reports;

use App\Actions\Reports\ManageReports;
use App\Models\AssignmentBatch;
use App\Models\Staff;
use App\Services\Reports\TaskReports;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function downloadReport(string $kind, ManageReports $reports): StreamedResponse
    {
        $file = $reports->download(auth()->user(), $this->filters, $kind);

        return response()->streamDownload(function () use ($file): void {
            echo $file['bytes'];
        }, $file['name'], ['Content-Type' => $file['mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function render(TaskReports $reports): View
    {
        $reports->authorize(auth()->user());
        $snapshot = $this->previewed ? $reports->snapshot(auth()->user(), $this->filters) : null;
        $rows = $snapshot ? new LengthAwarePaginator(array_slice($snapshot['rows'], ($this->getPage() - 1) * 20, 20), count($snapshot['rows']), 20, $this->getPage(), ['path' => request()->url()]) : null;
        $batches = AssignmentBatch::query()->where('status', 'active')->latest('id')->limit(200)->get();
        $riders = Staff::query()->where('is_rider', true)->orderBy('first_name')->limit(200)->get();

        return view('livewire.admin.reports.index', compact('snapshot', 'rows', 'batches', 'riders'));
    }
}
