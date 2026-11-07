<section class="rider-page">
<x-feedback />
@can('tasks.submit')<a href="{{ route('rider.tasks.manage', ['batch' => $batch->public_id]) }}" class="btn btn-secondary justify-self-start">Manage selected tasks</a>@endcan
<a href="{{ route('rider.assignments.index') }}" class="rider-back-link"><x-icon name="arrow-left" class="size-4" />Assignment</a>
<div class="surface rider-assignment-summary"><div class="flex items-start justify-between gap-3"><span class="rider-record-icon"><x-icon name="layers" /></span><x-badge>{{ ucfirst($batch->status->value) }}</x-badge></div><h1 class="page-heading">{{ $batch->name }}</h1><p class="text-sm text-ink-500">{{ $batch->assignment_code }} · {{ $batch->external_reference ?: 'No external reference' }}</p>
<dl class="rider-task-dates"><div><dt>Start date</dt><dd>{{ $batch->start_date->format('d M Y') }}</dd></div><div><dt>Due date</dt><dd>{{ $batch->due_date->format('d M Y') }}</dd></div></dl>
<div class="rider-assignment-counts">@foreach(['pending_count'=>'Pending','submitted_count'=>'In verification','completed_count'=>'Verified'] as $key=>$label)<div><strong>{{ $batch->{$key} }}</strong><span>{{ $label }}</span></div>@endforeach</div>
</div>
<div class="rider-section-heading"><h2>Your allocated tasks</h2><span>{{ $batch->task_count }} total</span></div>
<div class="rider-card-grid">@forelse($tasks as $task)<x-rider.task-card :task="$task" />@empty<x-empty-state title="No tasks available" message="Your current task allocation may have changed. Return to Assignment to refresh your work." />@endforelse</div>{{ $tasks->links() }}
</section>
