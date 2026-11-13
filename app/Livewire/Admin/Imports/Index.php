<?php

namespace App\Livewire\Admin\Imports;

use App\Actions\Imports\ImportWorkflow;
use App\Enums\BatchStatus;
use App\Models\AssignmentBatch;
use App\Models\ImportBatch;
use App\Models\ImportTemplate;
use App\Services\Customers\CustomerSearch;
use App\Services\Imports\TaskUploadTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithFileUploads,WithPagination;

    public array $data = ['assignment_batch_id' => ''];

    public $file;

    /** Statuses an upload can show in history; cancelled uploads are never listed. */
    public const STATUSES = ['uploaded', 'scanning', 'mapping', 'validating', 'ready', 'processing', 'completed', 'failed'];

    public string $search = '';

    public string $status = '';

    public string $from = '';

    public string $until = '';

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function uploadWorkbook(ImportWorkflow $workflow): void
    {
        $this->validate([
            'data.assignment_batch_id' => ['required', 'integer', Rule::exists('assignment_batches', 'id')->where('status', BatchStatus::Active->value)],
            'file' => ['required', 'file', 'max:'.config('imports.max_kilobytes')],
        ], attributes: ['data.assignment_batch_id' => 'assignment']);
        if (! CustomerSearch::isConfigured()) {
            $this->addError('file', 'Excel uploads are unavailable because DCMS_SEARCH_KEY is not set in .env. Run php artisan dcms:doctor for the steps to fix this.');

            return;
        }
        $template = ImportTemplate::query()->where('is_active', true)->where('kind', 'task')->orderByDesc('version')->first();
        if (! $template) {
            $this->addError('file', 'Task uploads are unavailable because no task template is published.');

            return;
        }
        try {
            $batch = $workflow->upload(Auth::user(), ['kind' => 'task', 'template_id' => $template->id, 'assignment_batch_id' => (int) $this->data['assignment_batch_id']], $this->file);
        } catch (ValidationException $error) {
            throw $error;
        } catch (\RuntimeException) {
            $this->addError('file', 'File storage is unavailable. Check the storage configuration before retrying.');

            return;
        }
        $isNewUpload = $batch->wasRecentlyCreated;
        $workflow->scan($batch->id);
        $rejection = $workflow->rejectionReason($batch->id);
        if ($rejection !== null) {
            $this->reset('file');
            $this->addError('file', 'Upload failed. '.$rejection.' No tasks were created.');

            return;
        }
        session()->flash('status', $isNewUpload ? 'Excel file uploaded. Check its columns and rows, then confirm to create the tasks.' : 'This Excel file was already uploaded to this assignment, so its earlier upload is open.');
        $this->redirectRoute('admin.imports.review', ['import' => $batch], navigate: false);
    }

    public function downloadTemplate(TaskUploadTemplate $template): StreamedResponse
    {
        Gate::authorize('create', ImportBatch::class);
        $path = $template->write();

        return response()->streamDownload(function () use ($path): void {
            readfile($path);
            @unlink($path);
        }, TaskUploadTemplate::FILE_NAME, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store']);
    }

    public function render(): View
    {
        Gate::authorize('viewAny', ImportBatch::class);
        $query = ImportBatch::query()->with(['template', 'source']);
        $query->where('status', '<>', 'cancelled')->where(fn ($q) => $q->where('status', '<>', 'failed')->orWhereNotNull('confirmed_at'));
        $search = trim(mb_substr($this->search, 0, 255));
        if ($search !== '') {
            $literal = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->whereHas('source', fn ($q) => $q->where('original_filename', 'ilike', $literal));
        }
        $query->when($this->status !== '', fn ($q) => $q->where('status', $this->status));
        $dates = Validator::make(['from' => $this->from ?: null, 'until' => $this->until ?: null], ['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d']]);
        if ($dates->fails()) {
            $query->whereRaw('false');
        } else {
            $query->when($this->from !== '', fn ($q) => $q->where('created_at', '>=', $this->from.' 00:00:00+08'))->when($this->until !== '', fn ($q) => $q->where('created_at', '<', Carbon::parse($this->until, 'Asia/Kuala_Lumpur')->addDay()->utc()));
        }

        $assignments = AssignmentBatch::query()->with('type')->where('status', BatchStatus::Active)->latest('id')->limit(200)->get();

        return view('livewire.admin.imports.index', ['imports' => $query->latest('id')->paginate(25), 'assignments' => $assignments, 'selectedAssignment' => $assignments->firstWhere('id', (int) $this->data['assignment_batch_id']), 'storageReady' => config('imports.disk') !== 's3' || filled(config('filesystems.disks.s3.bucket'))]);
    }
}
