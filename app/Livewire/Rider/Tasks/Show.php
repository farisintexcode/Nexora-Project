<?php

namespace App\Livewire\Rider\Tasks;

use App\Actions\Tasks\RiderWork;
use App\Actions\Tasks\TaskProof;
use App\Enums\BatchStatus;
use App\Models\ReferenceValue;
use App\Models\Task;
use App\Services\Tasks\CompletionChecklist;
use App\Services\Tasks\RiderAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.rider')]
class Show extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $taskId;

    #[Locked]
    public int $version;

    #[Locked]
    public string $visitKey;

    #[Locked]
    public string $submissionKey;

    #[Locked]
    public string $digest = '';

    #[Locked]
    public bool $visitDirty = false;

    #[Url]
    public string $section = 'overview';

    public array $data = [];

    public array $photos = [];

    public $camera;

    public string $category = 'premises';

    public string $caption = '';

    public string $notice = '';

    public function mount(Task $task): void
    {
        abort_unless(app(RiderAccess::class)->current(Auth::user(), $task), 404);
        $this->taskId = $task->id;
        $this->version = $task->lock_version;
        $this->visitKey = (string) Str::uuid7();
        $this->submissionKey = (string) Str::uuid7();
        $visit = $task->activities()->where('activity_type_code', 'visit')->where('performed_by', Auth::id())->latest('id')->first();
        $this->data = ['paid_amount' => $visit?->paid_amount_ciphertext ?? '', 'purpose' => $visit?->visit_purpose_code ?? 'site_visit', 'business_applicable' => $visit?->business_applicable ?? false, 'signboard_available' => $visit?->signboard_available === null ? '' : ($visit->signboard_available ? '1' : '0'), 'backdating_reason' => '', 'occurred_at' => '', 'ended_at' => '', 'site_address' => $visit?->site_address_ciphertext ?? $task->account->property?->address_lines_ciphertext ?? '',
            'dr_code' => $visit?->inspection?->dr_code ?? '', 'usage' => $visit?->inspection?->usage_code ?? '', 'property_type' => $visit?->inspection?->property_type_code ?? '', 'usage_other' => $visit?->inspection?->usage_other ?? '',
            'availability' => $visit?->availability_code ?? '', 'visit_status' => $visit?->visit_status_code ?? '', 'contact_result' => $visit?->contact_result_code ?? '', 'outcome' => $visit?->outcome_code ?? '',
            'person_contacted' => $visit?->person_contacted_ciphertext ?? '', 'visit_remarks' => $visit?->visit_remarks_ciphertext ?? '', 'collection_remarks' => $visit?->collection_remarks_ciphertext ?? '', 'customer_remarks' => $visit?->customer_remarks_ciphertext ?? '',
            'business_type' => $visit?->inspection?->business_type_code ?? '', 'business_nature' => $visit?->inspection?->business_nature_code ?? '', 'business_status' => $visit?->inspection?->business_status_code ?? '',
            'business_other' => $visit?->inspection?->business_other_ciphertext ?? '', 'shop_name' => $visit?->inspection?->shop_company_ciphertext ?? '', 'follow_up_due' => '', 'follow_up_remarks' => ''];
    }

    private function task(): Task
    {
        $task = Task::query()->findOrFail($this->taskId);
        abort_unless(app(RiderAccess::class)->current(Auth::user(), $task), 404);

        return $task;
    }

    public function start(RiderWork $work): void
    {
        $task = $work->start(Auth::user(), $this->task(), $this->version);
        $this->version = $task->lock_version;
        $this->notice = 'Task started. You can now record your visit.';
        $this->section = 'visit';
        $this->digest = '';
    }

    public function saveVisit(RiderWork $work): void
    {
        $input = $this->data;
        try {
            $work->visit(Auth::user(), $this->task(), $this->version, $input, $this->visitKey);
        } catch (ValidationException $error) {
            throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn ($messages, $key) => [in_array($key, ['version', 'status']) ? $key : 'data.'.$key => $messages])->all());
        }
        $this->version = $this->task()->lock_version;
        $this->visitKey = (string) Str::uuid7();
        $this->digest = '';
        $this->visitDirty = false;
        $this->notice = 'Visit and remarks saved. Previous visits remain in history.';
        $this->section = 'proof';
    }

    public function uploadProof(TaskProof $proof): void
    {
        if ($this->visitDirty) {
            $this->addError('proof', 'Save your changed visit details before uploading evidence.');

            return;
        }
        $this->validate(['photos' => ['required', 'array', 'min:1', 'max:10'], 'photos.*' => ['required', 'file', 'max:'.config('task_execution.max_image_kib')]]);
        foreach ($this->photos as $index => $photo) {
            try {
                $proof->upload(Auth::user(), $this->task(), $this->version, $photo, $this->category, $this->caption);
            } catch (ValidationException $error) {
                throw $error;
            } catch (\RuntimeException) {
                $this->addError('proof', 'Private upload is unavailable. Your selected files can be retried.');

                return;
            }
            $this->version = $this->task()->lock_version;
            unset($this->photos[$index]);
        }
        $this->photos = [];
        $this->digest = '';
        $this->notice = TaskProof::scanRequired() ? 'Images saved privately. Scans are pending; only clean images can be submitted.' : 'Images saved privately.';
    }

    public function discardPhoto(int $index): void
    {
        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
    }

    public function updatedCamera(): void
    {
        if ($this->camera) {
            $this->photos[] = $this->camera;
            $this->camera = null;
        }
    }

    public function removeProof(string $reference, TaskProof $proof): void
    {
        $proof->remove(Auth::user(), $this->task(), $this->version, $reference);
        $this->version = $this->task()->lock_version;
        $this->digest = '';
        $this->notice = 'Draft image removed. Retained evidence history is unchanged.';
    }

    public function retryScan(string $reference, TaskProof $proof): void
    {
        $proof->retry(Auth::user(), $this->task(), $this->version, $reference);
        $this->version = $this->task()->lock_version;
        $this->digest = '';
        $this->notice = 'Scan retry queued.';
    }

    public function reviewCompletion(CompletionChecklist $checklist): void
    {
        $task = $this->task();
        Gate::authorize('execute', $task);
        if ($this->visitDirty) {
            $this->section = 'visit';
            $this->addError('visit', 'Save your changed visit details before completing this task.');

            return;
        }
        $this->version = $task->lock_version;
        $this->digest = $checklist->review($task, Auth::user())['digest'];
        $this->section = 'completion';
        $this->resetErrorBag();
    }

    public function submitCompletion(RiderWork $work): void
    {
        if ($this->visitDirty) {
            throw ValidationException::withMessages(['visit' => 'Save your changed visit details before completing this task.']);
        }
        $submission = $work->submit(Auth::user(), $this->task(), $this->version, $this->submissionKey, $this->digest);
        $this->version = $this->task()->lock_version;
        $this->section = 'history';
        $this->notice = 'Task completed and submitted. Awaiting Admin verification · '.$submission->public_id;
        $this->dispatch('rider-task-completed');
    }

    public function updatedData(): void
    {
        $this->visitDirty = true;
        $this->digest = '';
    }

    public function render(CompletionChecklist $checklist): View
    {
        Gate::authorize('use-rider-interface');
        $task = $this->task()->load(['batch', 'customer.contacts', 'account.property', 'currentAllocation', 'latestBalance']);
        if (! in_array($this->section, ['overview', 'customer', 'visit', 'business', 'proof', 'completion', 'history'], true)) {
            $this->section = 'overview';
        }
        $review = $checklist->review($task, Auth::user());
        $choices = ReferenceValue::query()->whereIn('category', ['dr_code', 'property_usage', 'property_type', 'visit_purpose', 'availability', 'visit_status', 'contact_result', 'activity_outcome', 'business_type', 'business_nature', 'business_status', 'image_category'])->where('is_active', true)->orderBy('sort_order')->orderBy('code')->get()
            ->filter(fn (ReferenceValue $value): bool => $value->category !== 'activity_outcome' || ! isset($value->metadata['activity_types']) || in_array('visit', $value->metadata['activity_types'], true))->groupBy('category');

        return view('livewire.rider.tasks.show', [
            'task' => $task, 'review' => $review, 'choices' => $choices,
            'visitPolicy' => $checklist->policy($task, $this->data['purpose'] ?? 'site_visit'),
            'editable' => Auth::user()->can('execute', $task) && $task->batch->status === BatchStatus::Active && $task->work_status === 'in_progress' && $task->review_status === 'none',
            'visits' => $task->activities()->where('activity_type_code', 'visit')->where('performed_by', Auth::id())->with('inspection')->latest('id')->limit(30)->get(),
            'submissions' => $task->submissions()->where('submitted_by', Auth::id())->latest('submission_version')->limit(20)->get(),
            'returnReason' => $task->reviews()->where('decision', 'returned')->latest('submission_version')->first()?->reason ?? $task->history()->where('event_code', 'returned')->latest('id')->value('reason'),
        ]);
    }
}
