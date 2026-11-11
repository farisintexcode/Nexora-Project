@props(['icon', 'title', 'status' => 'live', 'href' => null, 'action' => null])
@php
    [$statusLabel, $statusTone] = match ($status) {
        'live' => ['Available', 'success'],
        'setup' => ['Setup needed', 'warning'],
        'locked' => ['Needs permission', 'warning'],
        default => ['Coming soon', 'neutral'],
    };
@endphp
<article {{ $attributes->class(['surface grid content-start gap-3 p-5', 'border-dashed' => $status === 'soon']) }}>
    <div class="flex items-start justify-between gap-3"><span @class(['icon-tile size-10 rounded-xl', 'text-ink-500' => $status === 'soon'])><x-icon :name="$icon" class="size-5" /></span><x-badge :tone="$statusTone">{{ $statusLabel }}</x-badge></div>
    <h3 class="text-sm font-semibold">{{ $title }}</h3>
    <p class="text-xs leading-5 text-ink-600">{{ $slot }}</p>
    @if($href && $action)<a class="btn btn-secondary btn-sm justify-self-start" href="{{ $href }}">{{ $action }}<x-icon name="arrow-right" class="size-4" /></a>@endif
</article>
