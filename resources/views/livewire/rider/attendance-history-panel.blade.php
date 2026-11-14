<section id="attendance-history" class="attendance-history-section" aria-labelledby="attendance-history-title">
<form wire:submit="$refresh" class="surface attendance-history-filters"><div class="flex items-center gap-3"><span class="attendance-icon"><x-icon name="calendar" /></span><div><h2 id="attendance-history-title" class="font-semibold">Attendance history</h2><p class="mt-1 text-xs text-ink-500">Review your shifts and recorded times</p></div></div><div class="grid min-w-0 gap-4 sm:grid-cols-2"><x-input name="from" type="date" label="History from" wire:model="from" class="min-w-0 max-w-full" /><x-input name="until" type="date" label="History through" wire:model="until" class="min-w-0 max-w-full" /></div><x-button variant="secondary" class="w-full sm:w-auto"><x-icon name="search" />View history</x-button></form>
@if($filterError)<p role="alert" class="rounded-xl bg-red-50 p-4 text-sm text-red-800">{{ $filterError }}</p>@endif
<div wire:loading.delay role="status" class="rounded-xl bg-brand-soft p-3 text-sm"><span class="loading-spinner"></span>Saving or updating attendance…</div>
<x-rider.attendance-history :rows="$rows" />{{ $rows->links() }}
</section>
