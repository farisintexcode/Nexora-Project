<?php

namespace App\Livewire\Admin\Imports;

use App\Actions\Imports\ImportWorkflow;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\ImportField;
use App\Models\ImportMappingProfile;
use App\Services\AuditWriter;
use App\Services\Imports\ImportTargets;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.admin')]
class Review extends Component
{
    use WithPagination;

    #[Locked]
    public string $importId;

    #[Locked]
    public string $digest = '';

    public string $sheet = '';

    public int $header = 1;

    public string $profileId = '';

    public string $profileName = '';

    public array $selections = [];

    public string $tab = 'summary';

    public string $rowState = '';

    public function mount(ImportBatch $import): void
    {
        $this->importId = $import->public_id;
        $batch = $this->batch();
        $this->sheet = $batch->selected_sheet ?? '';
        $this->header = $batch->header_row_number ?? 1;
        $this->hydrateMapping($batch);
        if ($batch->status === 'mapping' && $this->selections !== []) {
            $this->tab = 'mapping';
        }
    }

    private function batch(): ImportBatch
    {
        $batch = ImportBatch::query()->with(['template', 'source'])->where('public_id', $this->importId)->firstOrFail();
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

    public function autoMap(ImportWorkflow $workflow): void
    {
        $batch = $this->batch();
        $this->validate(['sheet' => ['required', 'string', 'max:100'], 'header' => ['required', 'integer', 'min:1', 'max:100'], 'profileId' => ['nullable', 'integer']]);
        try {
            $workflow->map(Auth::user(), $batch, $this->sheet, $this->header, $this->profileId !== '' ? (int) $this->profileId : null);
        } catch (\RuntimeException) {
            $this->addError('mapping', 'This workbook cannot be read safely. Check its structure, formulas and configured limits.');

            return;
        }
        $this->hydrateMapping($batch->refresh());
        $this->tab = 'mapping';
    }

    public function preview(ImportWorkflow $workflow): void
    {
        $batch = $this->batch();
        $workflow->preview(Auth::user(), $batch, $this->selections);
        $this->hydrateMapping($batch->refresh());
        $this->tab = 'rows';
        $this->resetPage();
    }

    public function saveProfile(ImportWorkflow $workflow): void
    {
        $workflow->profile(Auth::user(), $this->batch(), $this->profileName);
        session()->flash('success', 'A new reviewed profile version was saved.');
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
            session()->flash('status', ($batch->processed_count === 1 ? '1 task created.' : "{$batch->processed_count} tasks created.").($batch->failed_count > 0 ? " {$batch->failed_count} rows failed. See the Errors tab." : ''));
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
        session()->flash('success', 'Retry queued with the same frozen mapping and target revisions.');
    }

    public function downloadErrors(): StreamedResponse
    {
        $batch = $this->batch();
        $this->auditDownload($batch);

        return response()->streamDownload(function () use ($batch): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Row', 'State', 'Field', 'Error code']);
            foreach ($batch->rows()->whereIn('state', ['rejected', 'duplicate', 'failed'])->orderBy('row_number')->cursor() as $row) {
                foreach ($row->validation_errors as $error) {
                    fputcsv($stream, [$row->row_number, $row->state, preg_replace('/[^a-z0-9_.]/i', '', $error['field']), preg_replace('/[^a-z0-9_]/i', '', $error['code'])]);
                }
            }
            fclose($stream);
        }, 'import-errors-'.$batch->public_id.'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'no-store']);
    }

    private function auditDownload(ImportBatch $batch): void
    {
        app(AuditWriter::class)->record(Auth::user(), 'import_errors_downloaded', $batch->public_id, module: 'M04', entityType: 'import');
    }

    public function updatedRowState(): void
    {
        $this->resetPage();
    }

    public function render(ImportWorkflow $workflow): View
    {
        $batch = $this->batch();
        if ($this->selections === [] && $batch->status === 'mapping' && $batch->mappings()->exists()) {
            $this->sheet = $batch->selected_sheet ?? '';
            $this->header = $batch->header_row_number ?? 1;
            $this->hydrateMapping($batch);
            $this->tab = 'mapping';
        }
        $sheets = [];
        if ($batch->source?->scan_status === 'clean' && in_array($batch->status, ['mapping', 'ready'], true)) {
            try {
                $sheets = $workflow->sheets(Auth::user(), $batch);
            } catch (\RuntimeException) {
                $this->addError('mapping', 'Source storage is temporarily unavailable.');
            }
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

        return view('livewire.admin.imports.review', ['samples' => $samples, 'batch' => $batch, 'sheets' => $sheets, 'mappings' => $batch->mappings()->orderBy('source_column_index')->get(), 'fields' => ImportField::query()->whereIn('id', $batch->template->schema_definition['field_ids'])->get(), 'profiles' => ImportMappingProfile::query()->where('template_id', $batch->template_id)->where('is_active', true)->get(), 'rows' => $rows, 'audits' => Auth::user()->can('audit.view') ? AuditLog::query()->where('module', 'M04')->where('entity_id', $batch->public_id)->latest('id')->limit(30)->get() : collect()]);
    }
}
