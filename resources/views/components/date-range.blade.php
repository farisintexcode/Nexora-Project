@props(['name', 'label', 'from', 'until', 'placeholder' => 'Select a date or range'])
<div class="grid content-start gap-2" wire:ignore x-data="nexoraDateRange($wire, '{{ $from }}', '{{ $until }}')" x-on:keydown.escape="open && finish()">
    <label id="{{ $name }}-label" for="{{ $name }}" class="field-label">{{ $label }}</label>
    <button id="{{ $name }}" type="button" x-ref="trigger" class="field flex items-center justify-between gap-3 text-left" aria-labelledby="{{ $name }}-label {{ $name }}-value" aria-haspopup="dialog" x-bind:aria-expanded="open" x-on:click="toggle()">
        <span id="{{ $name }}-value" class="min-w-0 truncate" x-bind:class="summary ? '' : 'text-ink-500'" x-text="summary || @js($placeholder)"></span>
        <x-icon name="calendar" class="size-4 text-ink-500" />
    </button>
    <div class="date-range-panel" role="dialog" aria-label="Choose {{ $label }}" x-show="open" x-cloak x-anchor.bottom-start.offset.4="$refs.trigger" x-on:click.outside="close()">
        <div class="flex items-center justify-between gap-2">
            <button type="button" class="date-range-nav" aria-label="Previous month" x-on:click="shiftMonth(-1)"><x-icon name="chevron" class="size-4 rotate-180" /></button>
            <p class="text-sm font-semibold" aria-live="polite" x-text="monthLabel"></p>
            <button type="button" class="date-range-nav" aria-label="Next month" x-on:click="shiftMonth(1)"><x-icon name="chevron" class="size-4" /></button>
        </div>
        <div class="grid grid-cols-7 text-center text-[11px] font-semibold text-ink-500" aria-hidden="true">@foreach(['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] as $weekday)<span>{{ $weekday }}</span>@endforeach</div>
        <div class="grid grid-cols-7 gap-y-0.5" x-on:mouseleave="hovered = null">
            <template x-for="cell in cells" x-bind:key="cell.key">
                <div class="grid">
                    <template x-if="cell.iso">
                        <button type="button" class="date-range-day" x-bind:data-state="stateOf(cell.iso)" x-bind:data-today="cell.iso === today" x-bind:aria-label="cell.label" x-bind:aria-pressed="stateOf(cell.iso) === 'selected'" x-bind:aria-current="cell.iso === today ? 'date' : null" x-text="cell.day" x-on:click="choose(cell.iso)" x-on:dblclick="chooseSingleDay(cell.iso)" x-on:mouseenter="hovered = cell.iso" x-on:focus="hovered = cell.iso"></button>
                    </template>
                </div>
            </template>
        </div>
        <div class="flex items-start justify-between gap-3 border-t border-ink-100 pt-3">
            <p class="field-help" aria-live="polite" x-text="hint"></p>
            <button type="button" class="shrink-0 text-xs font-semibold underline underline-offset-4" x-on:click="clear()">Clear</button>
        </div>
    </div>
</div>
