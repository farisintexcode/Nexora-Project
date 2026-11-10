<section class="rider-page" wire:poll.15s>
<x-feedback />
<x-page-header title="Assignment" eyebrow="" description="Your assignments, with a clear view of the tasks allocated to you." />
<div class="rider-context-note"><x-icon name="shield" class="size-4" /><p>Only your current allocated tasks are shown.</p></div>
<div class="rider-card-grid">
@forelse($assignments as $assignment)
<article class="surface rider-assignment-card" wire:key="rider-assignment-{{ $assignment->public_id }}">
    <div class="flex items-start justify-between gap-3"><span class="rider-record-icon"><x-icon name="layers" /></span><x-badge :tone="$assignment->status->value==='active'?'brand':'neutral'">{{ ucfirst($assignment->status->value) }}</x-badge></div>
    <div><h2 class="rider-card-title">{{ $assignment->name }}</h2><p class="mt-2 break-words text-xs text-ink-500">{{ $assignment->assignment_code }} · {{ $assignment->external_reference ?: 'No external reference' }}</p></div>
    <div class="rider-assignment-progress"><div><span>{{ $assignment->completed_count }} of {{ $assignment->task_count }} completed</span><span>{{ $assignment->pending_count }} pending</span></div><div role="progressbar" aria-label="Completed tasks" aria-valuenow="{{ round($assignment->completed_count/max(1,$assignment->task_count)*100, 2) }}" aria-valuemin="0" aria-valuemax="100"><span style="width:{{ round($assignment->completed_count/max(1,$assignment->task_count)*100, 2) }}%"></span></div><p class="mt-2 text-xs text-ink-500">{{ $assignment->submitted_count }} awaiting Admin verification</p></div>
    <div class="rider-assignment-meta"><span><x-icon name="calendar" class="size-4" />Due {{ $assignment->due_date->format('d M Y') }}</span><span>{{ $assignment->task_count }} of your tasks</span></div>
    <a href="{{ route('rider.assignments.show',$assignment->public_id) }}" class="btn btn-primary w-full justify-between">View Assignment<x-icon name="arrow-right" class="size-4" /></a>
</article>
@empty<div class="md:col-span-2"><x-empty-state title="No assignments yet" message="Your allocated work will appear here when an Admin assigns tasks to you." icon="layers" /></div>@endforelse
</div>{{ $assignments->links() }}
</section>
