<?php

namespace App\Livewire\Admin\Verification;

use App\Actions\Tasks\ReviewSubmission;
use App\Models\TaskSubmission;
use App\Services\Tasks\SubmissionEvidence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.admin')]
class Show extends Component
{
    #[Locked]
    public string $submissionId;

    #[Locked]
    public array $preview = [];

    #[Locked]
    public array $results = [];

    public string $decision = 'approved';

    public string $reason = '';

    public bool $photoMatches = false;

    public bool $partialAccepted = false;

    public function mount(TaskSubmission $submission): void
    {
        $this->submissionId = $submission->public_id;
    }

    public function updated(): void
    {
        $this->preview = [];
    }

    public function prepareReview(ReviewSubmission $review): void
    {
        $this->preview = $review->prepare(Auth::user(), [$this->submissionId], $this->decision, $this->reason, [$this->submissionId => ['photo' => $this->photoMatches, 'partial' => $this->partialAccepted]]);
        $this->resetErrorBag();
    }

    public function confirmReview(ReviewSubmission $review): void
    {
        $this->results = $review->confirm(Auth::user(), $this->preview);
        $this->preview = [];
        $this->reason = '';
        session()->flash('status', 'Verification decision saved. The Rider has an in-app notification.');
    }

    public function render(SubmissionEvidence $evidence, ReviewSubmission $review): View
    {
        $submission = TaskSubmission::query()->with(['task.batch', 'task.account.property', 'task.customer', 'submitter', 'review.reviewer', 'documents.document'])->where('public_id', $this->submissionId)->firstOrFail();
        abort_unless(Auth::user()->isActive(), 404);
        Gate::authorize('view', $submission);
        $snapshot = $submission->completion_snapshot_ciphertext;
        $labels = collect($snapshot['lookup_labels'] ?? [])->keyBy(fn ($row) => $row['category'].'|'.$row['code']);
        $label = fn (string $category, ?string $code): string => $code === null ? '—' : ($labels->get($category.'|'.$code)['label'] ?? ucfirst(str_replace('_', ' ', $code)));
        $formatTime = function (?string $value): string {
            if (! $value) {
                return '—';
            }try {
                return Carbon::parse($value)->timezone(config('dcms.business_timezone'))->format('d M Y H:i').' MYT';
            } catch (\Throwable) {
                return 'Unavailable';
            }
        };
        $pending = TaskSubmission::query()->whereDoesntHave('review')->whereHas('task', fn ($q) => $q->where('work_status', 'submitted')->where('review_status', 'pending'));

        return view('livewire.admin.verification.show', [
            'submission' => $submission, 'snapshot' => $snapshot, 'payment' => $review->paymentClaim($submission), 'issues' => $evidence->issues($submission), 'referenceLabel' => $label, 'formatTime' => $formatTime,
            'previous' => (clone $pending)->where('id', '<', $submission->id)->orderByDesc('id')->first(),
            'next' => (clone $pending)->where('id', '>', $submission->id)->orderBy('id')->first(),
            'history' => $submission->task->submissions()->with('review.reviewer')->latest('submission_version')->limit(20)->get(),
            'back' => route('admin.verification.index', session('verification_filters.'.Auth::id(), [])),
        ]);
    }
}
