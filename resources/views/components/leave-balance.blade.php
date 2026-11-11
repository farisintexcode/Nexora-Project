@props(['balance', 'year', 'tracked' => true, 'name' => null, 'requested' => null])
@php
    $byApplications = ($balance['unit'] ?? 'days') === 'applications';
    $word = fn (int $count): string => $byApplications ? ($count === 1 ? 'application' : 'applications') : ($count === 1 ? 'working day' : 'working days');
    $limit = $balance['entitlement'] === null ? null : max(0, $balance['entitlement'] + ($balance['adjustments'] ?? 0));
    $requested = (int) ($requested ?? ($balance['requested'] ?? 0));
    $percent = fn (int $count): float => $limit ? min(100, round($count / $limit * 100, 1)) : 0;
    $afterThis = $balance['available'] === null ? null : $balance['available'] - $requested;
@endphp
<section {{ $attributes->class(['rounded-xl border border-ink-200 bg-white p-4']) }} aria-label="{{ $name ? $name.' balance' : 'Leave balance' }} {{ $year }}">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div><h3 class="text-sm font-semibold">{{ $name ?? 'Leave balance' }} · {{ $year }}</h3>
            <p class="mt-0.5 text-xs text-ink-500">@if(! $tracked) No yearly limit @elseif($limit === null) Yearly limit not set yet @else Limit {{ $limit }} {{ $word($limit) }} a year @endif</p></div>
        @if($tracked && $limit !== null)<p class="text-right"><span @class(['block text-2xl leading-none font-semibold tabular-nums', 'text-red-700' => $balance['available'] <= 0])>{{ max(0, $balance['available']) }}</span><span class="text-[11px] text-ink-500">left</span></p>@endif
    </div>
    @if(! $tracked)
        <p class="mt-3 text-xs text-ink-600">You can apply without using up a balance.</p>
    @elseif($limit === null)
        <p class="mt-3 rounded-lg bg-amber-50 p-2 text-xs text-amber-900">Your administrator hasn't set the {{ $year }} limit yet, so this leave can't be applied for.</p>
    @else
        <div class="mt-3 flex h-2.5 gap-0.5 overflow-hidden rounded-full bg-ink-100" role="img" aria-label="{{ $balance['used'] }} used, {{ $balance['pending'] }} waiting approval, {{ max(0, $balance['available']) }} left of {{ $limit }}">
            @if($balance['used'])<span class="h-full bg-brand-primary" style="width: {{ $percent($balance['used']) }}%"></span>@endif
            @if($balance['pending'])<span class="h-full bg-brand-accent" style="width: {{ $percent($balance['pending']) }}%"></span>@endif
            @if($requested)<span class="h-full bg-brand-accent/40 ring-1 ring-inset ring-brand-accent" style="width: {{ $percent($requested) }}%"></span>@endif
        </div>
        <dl class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs">
            <div class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-brand-primary"></span><dt class="text-ink-600">Used</dt><dd class="font-semibold tabular-nums">{{ $balance['used'] }}</dd></div>
            <div class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-brand-accent"></span><dt class="text-ink-600">Waiting approval</dt><dd class="font-semibold tabular-nums">{{ $balance['pending'] }}</dd></div>
            <div class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-ink-200"></span><dt class="text-ink-600">Left</dt><dd class="font-semibold tabular-nums">{{ max(0, $balance['available']) }}</dd></div>
        </dl>
        @if($requested)
            <p @class(['mt-3 rounded-lg p-2 text-xs', 'bg-brand-soft text-ink-800' => $afterThis >= 0, 'bg-red-50 text-red-800' => $afterThis < 0])>
                @if($afterThis >= 0) This application uses {{ $requested }} {{ $word($requested) }}. You'll have <strong>{{ $afterThis }}</strong> left. @else This application needs {{ $requested }} {{ $word($requested) }}, but only {{ max(0, $balance['available']) }} {{ $balance['available'] === 1 ? 'is' : 'are' }} left. @endif
            </p>
        @endif
    @endif
</section>
