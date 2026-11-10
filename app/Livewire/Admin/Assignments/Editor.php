<?php

namespace App\Livewire\Admin\Assignments;

use App\Actions\Assignments\SaveAssignmentBatch;
use App\Enums\BatchStatus;
use App\Models\AssignmentBatch;
use App\Models\AuditLog;
use App\Models\ReferenceValue;
use App\Services\Assignments\BatchWork;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** UI11/12; new assignments always start active and archive automatically once their work is verified (ArchiveFinishedBatch). */
#[Layout('layouts.admin')]
class Editor extends Component
{
    /** Operational order of assignment types; any others follow alphabetically. */
    private const TYPE_ORDER = ['vacant', 'domestic_dr', 'domestic_ctos', 'domestic_lod', 'domestic_lod_ctos', 'com_dr', 'com_lod'];

    #[Locked]
    public ?int $batchId = null;

    #[Locked]
    public ?int $version = null;

    public array $data = ['name' => '', 'external_reference' => '', 'type_code' => '', 'start_date' => '', 'due_date' => '', 'description' => '', 'dca_code' => '', 'dca_name' => '', 'la_name' => '', 'state_code' => ''];

    public function mount(?AssignmentBatch $batch = null): void
    {
        if (! $batch?->exists) {
            Gate::authorize('create', AssignmentBatch::class);
            $this->data['start_date'] = now()->timezone(config('dcms.business_timezone'))->toDateString();

            return;
        }
        abort_unless(Auth::user()->isActive(), 404);
        Gate::authorize('view', $batch);
        request()->attributes->set('assignment_status', $batch->status->value);
        $this->batchId = $batch->id;
        $this->version = $batch->lock_version;
        foreach (array_keys($this->data) as $field) {
            $this->data[$field] = in_array($field, ['start_date', 'due_date']) ? $batch->{$field}->format('Y-m-d') : ($batch->{$field} ?? '');
        }
    }

    public function save(SaveAssignmentBatch $save): void
    {
        $batch = $save->handle(Auth::user(), $this->data, $this->batchId ? AssignmentBatch::query()->findOrFail($this->batchId) : null, $this->version);
        session()->flash('status', 'Assignment saved. No tasks were uploaded or allocated.');
        $this->redirectRoute('admin.assignments.edit', ['batch' => $batch->public_id]);
    }

    public function render(BatchWork $work): View
    {
        $batch = $this->batchId ? AssignmentBatch::query()->with(['creator', 'type', 'state'])->findOrFail($this->batchId) : null;
        if ($batch) {
            abort_unless(Auth::user()->isActive(), 404);
            Gate::authorize('view', $batch);
        } else {
            Gate::authorize('create', AssignmentBatch::class);
        }
        $references = fn (string $category, ?string $current) => ReferenceValue::query()->where('category', $category)->where(fn ($q) => $q->where('is_active', true)->when($current, fn ($q) => $q->orWhere('code', $current)))->orderBy('label')->get();
        $typeOrder = array_flip(self::TYPE_ORDER);

        return view('livewire.admin.assignments.editor', [
            'batch' => $batch,
            'today' => now()->timezone(config('dcms.business_timezone'))->toDateString(),
            'types' => $references('assignment_type', $batch?->type_code)->sortBy(fn (ReferenceValue $type): int => $typeOrder[$type->code] ?? count($typeOrder))->values(),
            'states' => $references('state', $batch?->state_code),
            'readOnly' => $batch && ($batch->status === BatchStatus::Archived || ! Gate::allows('update', $batch)),
            'work' => $batch ? $work->summary($batch) : null,
            'activity' => $batch ? AuditLog::query()->where('module', 'M03')->where('entity_type', 'assignment_batch')->where('entity_id', $batch->public_id)->with('actor:id,name')->orderByDesc('id')->limit(10)->get() : collect(),
            'backFilters' => session('assignment_filters.'.Auth::id(), []),
        ]);
    }
}
