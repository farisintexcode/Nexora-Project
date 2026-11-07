<section class="rider-page">
    <x-feedback />
    <header class="grid gap-2"><h1 class="page-heading">Manage tasks</h1><p class="text-sm leading-6 text-ink-500">Update selected tasks in one assignment. Preview the exact selection before applying.</p><a href="{{ route('rider.tasks.index') }}" class="rider-back-link"><x-icon name="arrow-left" class="size-4" />Back to My Tasks</a></header>
    <div class="surface grid gap-4 p-5">
        <h2 class="font-semibold">Choose an update</h2>
        <p class="text-xs leading-6 text-ink-500">Start each task and save its visit draft first. Select up to 50 of your current tasks. Submitted and Completed tasks are read-only; returned work needs a new correction draft.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-select name="batch" label="Assignment" wire:model.live="batch"><option value="">Choose assignment</option>@foreach($batches as $assignment)<option value="{{ $assignment->public_id }}">{{ $assignment->assignment_code }} - {{ $assignment->name }}</option>@endforeach</x-select>
            <x-select name="operation" label="Type of manage" wire:model.live="operation">@foreach(\App\Actions\Tasks\RiderBatchUpdate::OPERATIONS as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</x-select>
        </div>
        @if(isset(\App\Actions\Tasks\RiderBatchUpdate::CATEGORIES[$operation]))
            <x-select name="value" label="New value" wire:model.live="value"><option value="">Select approved value</option>@foreach($choices as $choice)<option value="{{ $choice->code }}">{{ $choice->code }} · {{ $choice->label }}</option>@endforeach</x-select>
            @if($choices->isEmpty())<p role="status" class="rounded-xl bg-brand-soft p-3 text-sm">No approved options are configured for this field. Ask your administrator to configure the master data.</p>@endif
        @elseif(in_array($operation, ['visit_date','reminder']))
            <x-input name="value" :label="$operation === 'visit_date' ? 'Actual visit time (MYT)' : 'Reminder due (MYT)'" type="datetime-local" wire:model.live="value" class="min-w-0 max-w-full" />
        @else
            <p class="rounded-xl bg-brand-soft p-3 text-sm">Eligible tasks will become Pending Verification. Only independent Admin approval makes them Completed.</p>
        @endif
        @if($operation === 'visit_date')<p class="text-xs leading-6 text-ink-500">Actual visits may be today or yesterday in MYT. This creates a new visit draft. Earlier proof is retained in history; the new draft needs its own proof before submission.</p>@endif
        @if($operation === 'dr_code')<p class="text-xs leading-6 text-ink-500">DR01 is a payment claim, not a receipt. It needs the amount paid, entered on the task, and Payment Evidence before submission. DR02 needs a due follow-up.</p>@endif
        <x-input name="reason" label="Reason / explanation" wire:model.live.debounce.300ms="reason" required maxlength="2000" help="Recorded with the update. For Other selections, explain the value; for visit time changes, explain the correction." />
    </div>
    @if($tasks)
        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="font-semibold">Select tasks</h2><span class="text-xs text-ink-500">{{ count($selection) }} selected · maximum 50</span></div>
        @error('selection')<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
        <div class="rider-card-grid">@forelse($tasks as $task)
            <label class="surface flex min-w-0 cursor-pointer items-start gap-3 p-5" wire:key="manage-task-{{ $task->public_id }}">
                <input type="checkbox" wire:model.live="selection" value="{{ $task->public_id }}" class="mt-1 size-5 shrink-0 accent-brand-primary" aria-label="Select {{ $task->account->account_number }}" />
                <span class="grid min-w-0 gap-2"><strong class="break-words text-sm">{{ $task->customer->display_name_ciphertext }}</strong><span class="break-words text-xs text-ink-500">{{ $task->account->account_number }}</span><span class="text-xs">{{ $task->review_status === 'returned' ? 'Returned for correction' : ($task->work_status === 'submitted' ? 'Pending Verification' : ucfirst(str_replace('_',' ',$task->work_status))) }}</span></span>
            </label>
        @empty<x-empty-state title="No tasks in this assignment" message="Your allocation may have changed. Choose another assignment." />@endforelse</div>
        {{ $tasks->links() }}
        <x-button type="button" wire:click="previewUpdates" wire:loading.attr="disabled" :disabled="!count($selection) || (isset(\App\Actions\Tasks\RiderBatchUpdate::CATEGORIES[$operation]) && $choices->isEmpty())">Preview selected update</x-button>
    @endif
    @if($preview)
        <section class="surface grid gap-4 p-5" aria-labelledby="batch-preview-title">
            <h2 id="batch-preview-title" class="text-lg font-semibold">Review selected update</h2>
            <p class="break-words text-sm">{{ \App\Actions\Tasks\RiderBatchUpdate::OPERATIONS[$preview['input']['operation']] }}@if($preview['input']['operation'] !== 'submit') → {{ $preview['input']['value'] }}@endif</p>
            <p class="text-xs leading-6 text-ink-500">All selected tasks must pass. The system rechecks access, drafts and task versions when you apply; a failed check leaves every task unchanged.</p>
            @foreach($preview['rows'] as $row)
                <div class="grid gap-2 rounded-xl border border-ink-200 p-3">
                    <strong class="break-words text-sm">{{ $row['customer'] }}</strong><span class="text-xs">{{ $row['account'] }}</span><p class="break-words text-xs text-ink-500">{{ $row['before'] ?: 'Not recorded' }} → {{ $row['after'] }}</p>
                    @if($row['summary'])
                        <details class="grid gap-3"><summary class="min-h-11 cursor-pointer py-2 text-sm font-semibold">Saved visit, remarks & proof</summary><div class="grid gap-3 pt-2 text-sm">
                            <p>{{ $row['summary']['visit_time'] ? \Carbon\CarbonImmutable::parse($row['summary']['visit_time'])->timezone(config('dcms.business_timezone'))->format('d M Y H:i').' MYT' : 'No visit recorded' }} · {{ ucfirst(str_replace('_', ' ', $row['summary']['outcome'] ?? 'No outcome')) }}</p>
                            @foreach($row['summary']['remarks'] as $kind => $remark)<div><p class="text-xs text-ink-500">{{ ucfirst($kind) }} remarks</p><p class="whitespace-pre-line">{{ $remark ?: '—' }}</p></div>@endforeach
                            @foreach($row['summary']['business'] ?? [] as $kind => $value)@if($kind !== 'reference' && $value)<p class="break-words text-xs">{{ $kind === 'dr_code' ? 'DR code' : ucfirst(str_replace('_',' ',$kind)) }}: {{ $value }}</p>@endif @endforeach
                            @foreach($row['proof'] as $image)<a class="break-words text-xs underline" target="_blank" rel="noopener" href="{{ route('proof.show', ['document' => $image['reference']]) }}">{{ $image['label'] }} · {{ str_replace('_',' ',$image['category']) }} · {{ $image['status'] }}</a>@endforeach
                            <a href="{{ route('rider.tasks.show', ['task' => $row['task'], 'section' => 'completion']) }}" target="_blank" rel="noopener" class="btn btn-secondary">View saved task and images</a>
                        </div></details>
                    @endif
                    @forelse($row['issues'] as $issue)<p class="text-sm text-red-700">{{ $issue }}</p>@empty<x-badge tone="success">Ready</x-badge>@endforelse
                </div>
            @endforeach
            @error('preview')<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-3"><x-button type="button" wire:click="applyUpdates" wire:confirm="Apply the reviewed update to these selected tasks?" wire:loading.attr="disabled" :disabled="collect($preview['rows'])->contains(fn($row) => $row['issues'] !== [])">Apply selected update</x-button><x-button type="button" variant="secondary" wire:click="cancelPreview" wire:loading.attr="disabled">Cancel</x-button></div>
        </section>
    @endif
</section>
