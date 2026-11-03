<?php

namespace App\Livewire\Admin\Tasks;

use App\Actions\Tasks\ResolveTaskSource;
use App\Actions\Tasks\SaveTask;
use App\Models\AssignmentBatch;
use App\Models\Debtor;
use App\Models\ImportBatch;
use App\Models\ReferenceValue;
use App\Models\Task;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.admin')]
class Editor extends Component
{
    #[Locked]
    public ?int $taskId = null;

    #[Locked]
    public ?int $version = null;

    public array $data = ['assignment_batch_id' => '', 'import_id' => '', 'task_id' => '', 'customer_reference' => '', 'customer_name' => '', 'phone' => '', 'email' => '', 'outstanding_balance' => '', 'monthly_charge' => '', 'balance_date' => '', 'account_number' => '', 'address' => '', 'building' => '', 'postcode' => '', 'state_code' => '', 'priority_code' => 'normal', 'scheduled_visit_at' => '', 'due_at' => '', 'instructions' => '', 'admin_remarks' => ''];

    public function mount(?Task $task = null): void
    {
        Gate::authorize('viewAny', Task::class);
        abort_unless(Auth::user()->can($task ? 'tasks.update' : 'tasks.create'), 403);
        if ($task) {
            abort_unless(Auth::user()->isActive(), 404);
            Gate::authorize('update', $task);
            $this->taskId = $task->id;
            $this->version = $task->lock_version;
            $balance = $task->latestBalance;
            $this->data = ['outstanding_balance' => $balance?->outstandingCents() === null ? '' : Money::plain($balance->outstandingCents()), 'monthly_charge' => $balance?->monthlyCents() === null ? '' : Money::plain($balance->monthlyCents()), 'balance_date' => $balance?->balance_date?->toDateString() ?? '', 'assignment_batch_id' => (string) $task->assignment_batch_id, 'customer_id' => (string) $task->customer_id, 'debt_account_id' => (string) $task->debt_account_id, 'task_id' => $task->source_task_id ?? '', 'customer_reference' => $task->customer->customer_reference, 'customer_name' => $task->customer->display_name_ciphertext, 'account_number' => $task->account->account_number, 'priority_code' => $task->priority_code, 'scheduled_visit_at' => $task->scheduled_visit_at?->timezone(config('dcms.business_timezone'))->format('Y-m-d\TH:i') ?? '', 'due_at' => $task->due_at?->timezone(config('dcms.business_timezone'))->format('Y-m-d\TH:i') ?? '', 'instructions' => $task->special_instruction_ciphertext ?? '', 'admin_remarks' => $task->admin_remarks_ciphertext ?? ''];
        }
    }

    public function updatedData(mixed $value, string $key): void
    {
        if ($key === 'assignment_batch_id') {
            $this->data['import_id'] = '';
        }
    }

    public function save(SaveTask $save, ResolveTaskSource $source): void
    {
        if (! $this->taskId) {
            $this->validate([
                'data.assignment_batch_id' => ['required', 'integer'],
                'data.task_id' => ['required', 'string', 'max:100'],
                'data.customer_reference' => ['required', 'string', 'max:80'],
                'data.customer_name' => [Rule::requiredIf(fn (): bool => ! Debtor::query()->where('customer_reference', trim($this->data['customer_reference']))->exists()), 'nullable', 'string', 'max:200'],
                'data.account_number' => ['required', 'string', 'max:100'],
            ], ['data.customer_name.required' => 'Enter the customer name. This reference has not been used before.'], ['data.assignment_batch_id' => 'assignment', 'data.task_id' => 'task ID', 'data.customer_reference' => 'customer reference', 'data.customer_name' => 'customer name', 'data.account_number' => 'account number']);
        }
        $task = DB::transaction(function () use ($save, $source): Task {
            $input = $this->data;
            if (! $this->taskId) {
                [$customer, $account] = $source->handle(Auth::user(), $this->data);
                $input = ['customer_id' => $customer->id, 'debt_account_id' => $account->id] + $input;
                if (($this->data['import_id'] ?? '') !== '') {
                    $input['import_batch_id'] = ImportBatch::query()->where('public_id', $this->data['import_id'])->value('id') ?? 0;
                }
            }

            return $save->handle(Auth::user(), $input, $this->taskId ? Task::query()->findOrFail($this->taskId) : null, $this->version);
        });
        session()->flash('status', 'Task saved with its source and history preserved.');
        $this->redirectRoute('admin.tasks.show', ['task' => $task]);
    }

    public function render(): View
    {
        Gate::authorize('viewAny', Task::class);
        $task = $this->taskId ? Task::query()->with('batch')->findOrFail($this->taskId) : null;
        if ($task) {
            abort_unless(Auth::user()->isActive(), 404);
            Gate::authorize('update', $task);
        }

        $batches = AssignmentBatch::query()->with('type')->where(fn ($q) => $q->where('status', 'active')->when($task, fn ($q) => $q->orWhere('id', $task->assignment_batch_id)))->latest('id')->limit(200)->get();

        $selectedAssignment = $batches->firstWhere('id', (int) $this->data['assignment_batch_id']);

        return view('livewire.admin.tasks.editor', ['task' => $task, 'batches' => $batches, 'selectedAssignment' => $selectedAssignment, 'excelFiles' => ! $task && $selectedAssignment ? ImportBatch::query()->taskFilesFor($selectedAssignment->id)->get() : collect(), 'states' => ReferenceValue::query()->where('category', 'state')->where('is_active', true)->orderBy('label')->get(), 'priorities' => ReferenceValue::query()->where('category', 'priority')->where('is_active', true)->get()]);
    }
}
