@props(['balance', 'year', 'tracked' => true])
<section class="rounded-xl bg-brand-soft p-4" aria-label="Leave balance {{ $year }}">
<h3 class="text-sm font-semibold">Leave balance {{ $year }}</h3>
@if(!$tracked)<p class="mt-2 text-sm">Non-deductible / no tracked limit</p>@else
<dl class="mt-3 grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
@foreach(['entitlement'=>'Annual Entitlement','adjustments'=>'Authorized Adjustments','used'=>'Approved/Used Days','pending'=>'Pending Reserved Days','available'=>'Available Days'] as $key=>$label)<div><dt class="text-xs text-ink-500">{{ $label }}</dt><dd class="mt-1 font-semibold">{{ $balance[$key] ?? 'Not configured' }}</dd></div>@endforeach
</dl>@endif
</section>
