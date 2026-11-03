<?php

namespace App\Livewire\Admin\Imports;

use App\Actions\Imports\ImportWorkflow;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\ImportField;
use App\Services\Imports\ImportTargets;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Review extends Component
{
    use WithPagination;

    #[Locked]
    public string $importId;

    #[Locked]
    public string $digest = '';

    public array $selections = [];

    public string $tab = 'summary';

    public string $rowState = '';

    public function mount(ImportBatch $import): void
    {
        $this->importId = $import->public_id;
        $batch = $this->batch();
        $this->hydrateMapping($batch);
        if ($batch->status === 'mapping' && $this->selections !== []) {
            $this->tab = 'mapping';
        }
    }

    private function batch(): ImportBatch
    {
        $batch = ImportBatch::query()->with(['template', 'source', 'assignment', 'uploader'])->where('public_id', $this->importId)->firstOrFail();
        abort_unless(Auth::user()->isActive(), 404);
        Gate::authorize('view', $batch);

        return $batch;
    }

    private function hydrateMapping(ImportBatch $batch): void
    {
        $this->digest = $batch->mapping_digest ?? '';
        $this->selections = [];
        foreach ($batch->mappings()->get() as $mapping) {
            $this->selections[$mapping->id] = ['field_id' => $mapping->is_ignored ? 'ignore' : ($mapping->field_id ? (string) $mapping->field_id : ''), 'transform' => $mapping->transform_code ?? 'exact'];
        }
    }

    public function preview(ImportWorkflow $workflow): void
    {
        $batch = $this->batch();
        $workflow->preview(Auth::user(), $batch, $this->selections);
        $this->hydrateMapping($batch->refresh());
        $this->tab = 'rows';
        $this->resetPage();
    }

    public function confirm(ImportWorkflow $workflow): void
    {
        $batch = $this->batch();
        foreach ($batch->mappings()->get() as $mapping) {
            $selection = $this->selections[$mapping->id] ?? [];
            if ((string) ($selection['field_id'] ?? '') !== ($mapping->is_ignored ? 'ignore' : (string) $mapping->field_id) || ($selection['transform'] ?? '') !== $mapping->transform_code) {
                throw ValidationException::withMessages(['confirmation' => 'The selected mapping changed. Validate and review a fresh preview before committing.']);
            }
        }
        $workflow->confirm(Auth::user(), $batch, $this->digest);
        if (config('queue.default') === 'sync') {
            $workflow->process($batch->id);
            $batch->refresh();
            $created = $batch->processed_count === 1 ? '1 task created.' : "{$batch->processed_count} tasks created.";
            if ($batch->failed_count > 0) {
                session()->flash('warning', $created.' '.($batch->failed_count === 1 ? '1 row' : "{$batch->failed_count} rows").' failed. See Row preview.');
            } else {
                session()->flash('status', $created);
            }
        } else {
            session()->flash('status', 'Accepted rows confirmed. The tasks are being created in the background.');
        }
        $this->tab = 'summary';
    }

    public function cancel(ImportWorkflow $workflow): void
    {
        $workflow->cancel(Auth::user(), $this->batch());
        session()->flash('status', 'Import cancelled. Previously processed rows remain in history.');
    }

    public function retry(ImportWorkflow $workflow): void
    {
        $workflow->retry(Auth::user(), $this->batch());
        session()->flash('status', 'Retry queued with the same column mapping. Tasks already created stay as they are.');
    }

    public function updatedRowState(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $batch = $this->batch();
        if ($this->selections === [] && $batch->status === 'mapping' && $batch->mappings()->exists()) {
            $this->hydrateMapping($batch);
            $this->tab = 'mapping';
        }
        $rows = $batch->rows()->when($this->rowState !== '', fn ($q) => $q->where('state', $this->rowState))->orderBy('row_number')->paginate(25);
        $rows->getCollection()->transform(function ($row) {
            $payload = $row->normalized_payload_ciphertext;

            return ['public_id' => $row->public_id, 'row_number' => $row->row_number, 'state' => $row->state, 'errors' => $row->validation_errors, 'operation' => $payload['operation'] ?? '—', 'changes' => $payload['changes'] ?? []];
        });

        $samples = [];
        if ($this->tab === 'mapping') {
            foreach ($batch->rows()->orderBy('row_number')->limit(3)->get() as $sample) {
                foreach ($sample->raw_payload_ciphertext as $column => $value) {
                    $samples[$column][] = app(ImportTargets::class)->mask($value);
                }
            }
        }

        return view('livewire.admin.imports.review', ['samples' => $samples, 'batch' => $batch, 'mappings' => $batch->mappings()->orderBy('source_column_index')->get(), 'fields' => ImportField::query()->whereIn('id', $batch->template->schema_definition['field_ids'])->get(), 'rows' => $rows, 'audits' => Auth::user()->can('audit.view') ? AuditLog::query()->with('actor')->where('module', 'M04')->where('entity_id', $batch->public_id)->latest('id')->limit(30)->get() : collect()]);
    }
}
