@props(['name', 'count' => 0, 'submit' => '$refresh', 'reset' => 'resetFilters', 'live' => false])
<form wire:submit="{{ $submit }}" class="surface grid min-w-0 gap-5 p-4 sm:p-5" data-start-open="{{ $count > 0 ? '1' : '0' }}" x-data="{ filtersOpen: $el.dataset.startOpen === '1' }">
    <div class="grid min-w-0 items-end gap-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
        {{ $slot }}
        <x-filter-toggle :controls="$name.'-filters'" :count="$count" />
    </div>
    <div id="{{ $name }}-filters" class="grid min-w-0 gap-4 border-t border-ink-100 pt-5" x-show="filtersOpen" x-cloak>{{ $advanced ?? '' }}</div>
    <div class="flex flex-wrap items-center justify-end gap-3"><button type="button" class="btn btn-secondary min-h-11" wire:click="{{ $reset }}">Clear filters</button>@unless($live)<button type="submit" class="btn assignment-apply min-h-11">Apply filters</button>@endunless</div>
</form>
