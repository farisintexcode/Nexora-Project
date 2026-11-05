<section class="grid gap-6">
<x-feedback />
<x-page-header title="Reports" eyebrow="Operational insight" description="Preview authorized work and download it as a CSV or PDF. Submitted work is shown separately from verified completion." />
<form wire:submit="preview" class="surface grid gap-6 p-5 sm:p-6">
<div class="flex items-center gap-3"><span class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-dark"><x-icon name="filter" /></span><div><h2 class="text-lg font-semibold">Build your report</h2><p class="mt-0.5 text-xs text-ink-500">Choose the report and filters, then preview it before downloading.</p></div></div>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
<x-select name="filters.family" label="Report family" wire:model.live="filters.family">@foreach(\App\Services\Reports\TaskReports::FAMILIES as $value=>$label)@if($value!=='verification'||auth()->user()->can('tasks.verify'))<option value="{{ $value }}">{{ $label }}</option>@endif @endforeach</x-select>
<x-date-range name="task-date" label="Task Date" from="filters.from" until="filters.until" />
<x-select name="filters.batch" label="Assignment" wire:model="filters.batch"><option value="">All assignments</option>@foreach($batches as $batch)<option value="{{ $batch->public_id }}">{{ $batch->id }} - {{ $batch->name }}</option>@endforeach</x-select>
<x-select name="filters.status" label="Work status" wire:model="filters.status"><option value="">All statuses</option>@foreach(['unstarted'=>'Assigned · not started','in_progress'=>'In progress','submitted'=>'Pending verification','completed'=>'Verified / completed','cancelled'=>'Cancelled'] as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-select>
<x-select name="filters.rider" label="Rider" wire:model="filters.rider"><option value="">Any Rider</option>@foreach($riders as $rider)<option value="{{ $rider->public_id }}">{{ $rider->staff_no }} · {{ $rider->full_name }}</option>@endforeach</x-select>
<x-input name="filters.task_id" label="Task ID" wire:model="filters.task_id" placeholder="12, 15 or 10-20" maxlength="500" help="One ID, several separated by commas, a range, or a mix: 10-20, 25." />
</div>
@if($filters['family']==='rider_performance')
<p class="rounded-xl bg-ink-50 p-4 text-xs leading-5 text-ink-600"><span class="font-semibold">Output columns:</span> Rider Performance uses a fixed set of summary columns.</p>
@else
@php
    $columnLabels = \App\Services\Reports\TaskReports::COLUMNS;
    $columnGroups = ['Task' => ['task_reference', 'task_id', 'batch', 'excluded', 'payment_update'], 'Customer & Rider' => ['customer_reference', 'account_reference', 'rider_staff_no'], 'Status' => ['work_status', 'review_status'], 'Dates' => ['created_at', 'due_at', 'submitted_at', 'completed_at'], 'Amounts' => ['outstanding_balance', 'monthly_charge', 'collected']];
    $ungroupedColumns = array_values(array_diff(array_keys($columnLabels), array_merge(...array_values($columnGroups))));
    if ($ungroupedColumns !== []) {
        $columnGroups['Other'] = $ungroupedColumns;
    }
@endphp
<details wire:ignore.self class="group rounded-2xl border border-ink-200">
    <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 [&::-webkit-details-marker]:hidden">
        <span class="flex items-center gap-3"><x-icon name="chevron" class="size-4 text-ink-500 transition-transform group-open:rotate-90" /><span class="text-sm font-semibold">Output columns</span></span>
        <span class="rounded-lg bg-brand-soft px-2.5 py-1 text-[11px] font-semibold text-brand-dark" x-text="$wire.filters.columns.length + ' of {{ count($columnLabels) }} selected'">{{ count($filters['columns']) }} of {{ count($columnLabels) }} selected</span>
    </summary>
    <div class="grid gap-5 border-t border-ink-100 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="max-w-2xl text-xs leading-5 text-ink-500">Account references are always masked and contact details are excluded. Collected counts verified payments only.</p>
            <div class="flex flex-wrap gap-4 text-xs font-semibold"><button type="button" class="underline underline-offset-4" x-on:click="$wire.set('filters.columns', {{ \Illuminate\Support\Js::from(array_keys($columnLabels)) }}, false)">Select all</button><button type="button" class="underline underline-offset-4" x-on:click="$wire.set('filters.columns', {{ \Illuminate\Support\Js::from(\App\Services\Reports\TaskReports::DEFAULT_COLUMNS) }}, false)">Use defaults</button></div>
        </div>
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @foreach($columnGroups as $groupName => $groupColumns)
            <fieldset class="min-w-0"><legend class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-ink-500">{{ $groupName }}</legend><div class="grid gap-2">@foreach($groupColumns as $key)@if(isset($columnLabels[$key]))<label class="flex min-h-10 cursor-pointer items-center gap-3 rounded-lg border border-ink-200 px-3 py-2 text-xs transition-colors hover:border-ink-300 has-[:checked]:border-brand-primary has-[:checked]:bg-brand-soft has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-accent" wire:key="report-column-{{ $key }}"><input type="checkbox" class="size-4 shrink-0 accent-brand-primary" wire:model="filters.columns" value="{{ $key }}"><span class="min-w-0">{{ $columnLabels[$key] }}</span></label>@endif @endforeach</div></fieldset>
            @endforeach
        </div>
    </div>
</details>
@endif
<div class="flex flex-wrap items-center justify-between gap-4 border-t border-ink-100 pt-5">
<p class="max-w-2xl flex-1 basis-80 text-xs leading-5 text-ink-500">Dates filter task creation in {{ config('dcms.business_timezone') }}. Rider metrics describe current allocations, including completed tasks. Amounts come from uploads and verified Rider claims and are not payment receipts. Maximum {{ number_format(config('reports.max_tasks')) }} source tasks per snapshot.</p>
<div class="flex flex-wrap gap-3"><x-button type="button" variant="secondary" wire:click="clearFilters">Reset filters</x-button><x-button type="submit" wire:loading.attr="disabled"><x-icon name="chart" />Preview report</x-button></div>
</div>
</form>
@if($errors->any())<div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<div wire:loading.delay role="status" class="text-sm text-ink-500"><span class="loading-spinner"></span>Preparing the report…</div>
@if($snapshot)
<div class="grid gap-4 sm:grid-cols-2">@foreach(['outstanding'=>'Total outstanding','collected'=>'Verified collected'] as $key=>$label)<div class="metric-card"><p class="text-xs text-ink-500">{{ $label }}</p><p class="mt-3 text-3xl font-semibold">{{ $snapshot['money'][$key] ?? '—' }}</p></div>@endforeach</div>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">@foreach(['tasks'=>'Matching tasks','completed'=>'Verified / completed','pending_verification'=>'Pending verification','returned'=>'Returned'] as $key=>$label)<div class="metric-card"><p class="text-xs text-ink-500">{{ $label }}</p><p class="mt-3 text-3xl font-semibold">{{ number_format($snapshot['totals'][$key]) }}</p></div>@endforeach</div>
<section class="surface min-w-0 overflow-hidden"><header class="flex flex-wrap items-center justify-between gap-4 border-b border-ink-200 p-5"><div><h2 class="font-semibold">{{ \App\Services\Reports\TaskReports::FAMILIES[$filters['family']] }} preview</h2><p class="mt-2 text-xs text-ink-500">As of {{ $snapshot['as_of'] }} · {{ count($snapshot['rows']) }} result rows</p></div>@can('reports.export')<div class="flex flex-wrap gap-2"><x-button type="button" variant="secondary" wire:click="downloadReport('csv')" wire:loading.attr="disabled" wire:target="downloadReport">Download CSV</x-button><x-button type="button" wire:click="downloadReport('pdf')" wire:loading.attr="disabled" wire:target="downloadReport">Download PDF</x-button></div>@endcan</header>
@if($rows->isNotEmpty())<div class="overflow-x-auto"><table class="w-full text-left text-xs"><thead><tr>@foreach($snapshot['columns'] as $label)<th class="whitespace-nowrap px-4 py-3">{{ $label }}</th>@endforeach</tr></thead><tbody>@foreach($rows as $row)<tr>@foreach($snapshot['columns'] as $key=>$label)<td class="max-w-xs break-words px-4 py-3">{{ $row[$key] ?? '' }}</td>@endforeach</tr>@endforeach</tbody></table></div><div class="p-5">{{ $rows->links() }}</div>@else<x-empty-state title="No matching tasks" message="Adjust the date range, assignment or status." icon="chart" />@endif
</section>
@else<x-empty-state title="Choose your report criteria" message="Preview the matching work before downloading it as a CSV or PDF." icon="chart" />@endif
</section>
