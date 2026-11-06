<section class="grid min-w-0 gap-6">
<x-feedback />
<x-page-header title="Task verification" eyebrow="Independent review" description="Review immutable Rider submissions. Only verified work becomes Completed." />
<form class="surface grid min-w-0 gap-5 p-4 sm:p-5" wire:submit="applyFilters" data-start-open="{{ $activeFilterCount > 0 ? '1' : '0' }}" x-data="{ filtersOpen: $el.dataset.startOpen === '1' }">
<div class="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
<x-select name="batch" label="Assignment" wire:model.live="batch"><option value="">All assignments</option>@foreach($batches as $batchOption)<option value="{{ $batchOption->public_id }}">{{ $batchOption->assignment_code }} - {{ $batchOption->name }}</option>@endforeach</x-select>
<x-select name="status" label="Verification status" wire:model.live="status"><option value="pending">Pending verification</option><option value="approved">Approved / Completed</option><option value="returned">Returned to Rider</option><option value="all">All submission cycles</option></x-select>
<x-input name="query" label="Account or customer reference" wire:model.live.debounce.400ms="query" placeholder="Exact reference" />
<button type="button" class="btn btn-secondary min-h-11 shrink-0" x-on:click="filtersOpen = !filtersOpen" x-bind:aria-expanded="filtersOpen" aria-controls="verification-filters"><x-icon name="filter" class="size-4" />Filters @if($activeFilterCount)<span class="assignment-filter-count" aria-label="{{ $activeFilterCount }} applied">{{ $activeFilterCount }}</span>@endif<x-icon name="chevron" class="size-4 transition-transform" x-bind:class="filtersOpen ? '-rotate-90' : 'rotate-90'" /></button>
</div>
<div id="verification-filters" class="grid gap-5 border-t border-ink-100 pt-5" x-show="filtersOpen" x-cloak>
<div class="grid gap-4 sm:grid-cols-2">
<x-select name="filter-rider" label="Rider" wire:model="draft.rider"><option value="">Any Rider</option>@foreach($riders as $riderOption)<option value="{{ $riderOption->public_id }}">{{ $riderOption->staff_no }} · {{ $riderOption->full_name }}</option>@endforeach</x-select>
<x-date-range name="submitted-date" label="Submitted Date" from="draft.from" until="draft.until" />
</div>
<div class="flex flex-wrap items-center justify-between gap-3"><button type="button" class="min-h-11 text-xs font-semibold text-ink-700 underline decoration-ink-300 underline-offset-4 hover:decoration-brand-accent" wire:click="resetFilters">Clear all filters</button><div class="flex flex-wrap gap-3"><button type="button" class="btn btn-secondary min-h-11 py-2" wire:click="resetAdvancedFilters">Reset</button><button type="submit" class="btn assignment-apply min-h-11 py-2">Apply filters</button></div></div>
</div>
</form>
@if($dateError)<p role="alert" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-950">{{ $dateError }}</p>@endif
<div wire:loading.delay role="status" class="rounded-xl bg-brand-soft p-3 text-sm">Updating verification workspace…</div>
<div class="grid gap-4 xl:grid-cols-2">
@forelse($submissions as $submission)
<article class="surface grid gap-4 p-5" wire:key="verify-{{ $submission->public_id }}"><div class="flex flex-wrap items-center justify-between gap-3"><x-badge :tone="$submission->review?->decision==='approved'?'success':($submission->review?'warning':'brand')">{{ $submission->review?->decision??'Pending verification' }}</x-badge></div>
<h2 class="font-semibold">{{ $submission->completion_snapshot_ciphertext['customer']['name']??'Customer' }}</h2><p class="text-sm text-ink-600">{{ $submission->completion_snapshot_ciphertext['account']??'—' }} · {{ $submission->task->batch->assignment_code }} - {{ $submission->task->batch->name }}</p><p class="text-xs text-ink-500">{{ $submission->submitter->name }} · Version {{ $submission->submission_version }} · {{ $submission->submitted_at->timezone(config('dcms.business_timezone'))->format('d M Y H:i') }} MYT</p>@if($submission->completion_snapshot_ciphertext['payment']['claimed'] ?? false)<p class="text-sm font-semibold">Payment claim {{ \App\Support\Money::format(\App\Support\Money::cents($submission->completion_snapshot_ciphertext['payment']['amount'] ?? null)) }} · open to confirm</p>@endif<p class="break-all text-[11px] text-ink-500">{{ $submission->public_id }}</p><a href="{{ route('admin.verification.show',$submission) }}" class="btn btn-primary">Review submission</a></article>
@empty<x-empty-state title="No submissions in this view" message="Completed Rider drafts appear here after a valid submission. Try another filter." icon="shield" />@endforelse
</div>{{ $submissions->links() }}
</section>

