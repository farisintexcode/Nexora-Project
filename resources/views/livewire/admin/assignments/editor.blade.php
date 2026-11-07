<section class="grid gap-6">
    <x-feedback />
    <x-page-header :title="$batch ? $batch->name : 'New assignment'" :eyebrow="$batch ? $batch->assignment_code : 'Assignment management'" description="Maintain work-package metadata. Collector allocation is a separate task workflow."><x-slot:actions><a class="btn btn-secondary" href="{{ route('admin.assignments.index', $backFilters) }}"><x-icon name="arrow-left" class="size-4" />Back to assignments</a>@if($batch)<x-badge :tone="$batch->status->value === 'active' ? 'brand' : 'neutral'">{{ ucfirst($batch->status->value) }} · Version {{ $batch->lock_version }}</x-badge>@endif</x-slot:actions></x-page-header>
    @if($readOnly)<p role="status" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm">{{ $batch->status->value === 'archived' ? 'Archived assignment: metadata is read-only. History is retained.' : 'You have view-only access to this assignment.' }}</p>@endif
    <form wire:submit="save" class="grid gap-6">
        <fieldset class="form-section grid gap-5 sm:grid-cols-2" @disabled($readOnly)><legend>Assignment</legend>
            <x-select name="data.type_code" label="Assignment type" wire:model="data.type_code" required><option value="">Choose type</option>@foreach($types as $item)<option value="{{ $item->code }}">{{ $item->label }}{{ !$item->is_active ? ' (inactive — retained)' : '' }}</option>@endforeach</x-select>
            <x-input name="data.name" label="Assignment name" wire:model="data.name" required maxlength="200" />
            <x-input name="data.due_date" label="Assignment due date" type="date" wire:model="data.due_date" required :min="$batch ? null : $today" :help="$batch ? 'Cannot be earlier than the assignment date ('.$batch->start_date->format('d M Y').').' : 'Today or later.'" />
            <div class="sm:col-span-2"><x-textarea name="data.description" label="Description (optional)" wire:model="data.description" maxlength="5000" rows="3" /></div>
        </fieldset>
        <fieldset class="form-section grid gap-5 sm:grid-cols-2" @disabled($readOnly)><legend>DCA & location</legend>
            <x-input name="data.dca_code" label="DCA code" wire:model="data.dca_code" maxlength="50" />
            <x-input name="data.dca_name" label="DCA name" wire:model="data.dca_name" maxlength="200" />
            <x-input name="data.la_name" label="LA name" wire:model="data.la_name" maxlength="200" />
            <x-select name="data.state_code" label="State" wire:model="data.state_code"><option value="">Not specified</option>@foreach($states as $item)<option value="{{ $item->code }}">{{ $item->label }}{{ !$item->is_active ? ' (inactive — retained)' : '' }}</option>@endforeach</x-select>
            @unless($batch)<x-select name="status" label="Status" wire:model="status" required><option value="active">Active</option><option value="archived">Archived</option></x-select>@endunless
        </fieldset>
        @if(!$readOnly)<div class="surface flex flex-wrap items-center justify-between gap-4 p-5"><p class="field-help">Saving records metadata only. Existing records are checked for concurrent edits.</p><x-button wire:loading.attr="disabled">{{ $batch ? 'Update assignment' : 'Save assignment' }}</x-button></div>@endif
    </form>
    @if($batch)
    <div class="grid items-start gap-6 xl:grid-cols-2"><section class="surface grid gap-4 p-6"><h2 class="text-base font-semibold">Work disposition</h2><dl class="grid grid-cols-2 gap-4 text-xs"><div><dt class="text-ink-500">Tasks</dt><dd class="mt-2 font-semibold">{{ $work['tasks'] === null ? 'Task module pending' : $work['tasks'].' total · '.$work['unfinished_tasks'].' unfinished' }}</dd></div><div><dt class="text-ink-500">Workbook imports</dt><dd class="mt-2 font-semibold">{{ $work['imports'] === null ? 'Import module pending' : $work['imports'].' total · '.$work['unfinished_imports'].' pending' }}</dd></div></dl><p class="field-help">Task monitoring and reviewed allocation are available. Legacy task import and reports remain gated by their source contracts.</p></section>
    <section class="surface grid gap-4 p-6"><h2 class="text-base font-semibold">{{ $batch->status->value === 'active' ? 'Archive assignment' : 'Reopen assignment' }}</h2>
    @can('update',$batch)
        @if($batch->status->value === 'active' || auth()->user()->can('reopen', $batch))
        <p class="field-help">{{ $batch->status->value === 'active' ? 'Archiving checks unfinished tasks and pending imports, then makes metadata read-only. No history is deleted.' : 'Reopening requires assignments.reopen and a reason. Historical changes remain audited.' }}</p>
        <form wire:submit="changeStatus('{{ $batch->status->value === 'active' ? 'archived' : 'active' }}')" class="grid gap-4"><x-textarea name="reason" label="Status change reason" wire:model="reason" required maxlength="1000" rows="2" /><x-confirm-action :label="$batch->status->value === 'active' ? 'Archive assignment' : 'Reopen assignment'" title="Confirm assignment status change" message="The server will recheck your permission, record version and work disposition before applying this audited change. Unsaved metadata edits will not be saved by this status change." /></form>
        @else<p class="field-help">Reopening requires the separately authorized assignments.reopen permission.</p>@endif
    @else<p class="field-help">Status changes require assignment management permission.</p>@endcan
    </section></div>
    <section class="surface p-6">@can('viewAny',\App\Models\Task::class)<a class="btn btn-secondary mb-5" href="{{ route('admin.tasks.index',['batch'=>$batch->public_id]) }}">View batch tasks</a>@endcan<h2 class="text-base font-semibold">Recent assignment activity</h2><p class="mt-2 field-help">Latest ten events · {{ config('dcms.business_timezone') }}. Free-text metadata is omitted from audit payloads.</p><div class="mt-5 grid gap-4">@forelse($activity as $event)<article class="flex gap-3 border-b border-ink-100 pb-4"><span class="icon-tile !size-9"><x-icon name="activity" class="size-4" /></span><div class="min-w-0"><p class="text-sm font-semibold">{{ ucfirst(str_replace('_',' ',$event->action)) }}</p><p class="mt-2 text-xs text-ink-500">{{ $event->actor?->name ?? 'System' }} · {{ $event->occurred_at->timezone(config('dcms.business_timezone'))->format('d M Y H:i') }}</p>@if($event->reason)<p class="mt-2 break-words text-xs leading-6">Reason: {{ $event->reason }}</p>@endif</div></article>@empty<p class="field-help">No assignment activity recorded.</p>@endforelse</div></section>
    @endif
</section>

