@props(['preview'=>[],'results'=>[]])
@if($preview)
<div class="surface grid scroll-mt-6 gap-4 p-6" x-data x-init="$nextTick(() => { $el.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' }); $refs.heading.focus({ preventScroll: true }) })"><h2 class="text-lg font-semibold focus:outline-none" tabindex="-1" x-ref="heading">Confirm exact review selection</h2><p class="text-sm text-ink-600">{{ count($preview['rows']) }} submission(s) · {{ $preview['decision']==='approved'?'Approve & complete':'Return for correction' }}. Every row must pass. No partial decisions will be applied.</p>
@if($preview['reason'])<p class="whitespace-pre-line rounded-xl bg-ink-50 p-4 text-sm">{{ $preview['reason'] }}</p>@endif
@foreach($preview['rows'] as $row)<div class="grid gap-2 rounded-xl border border-ink-200 p-4"><strong class="text-sm">{{ $row['customer'] }}</strong><span class="break-all text-xs text-ink-500">{{ $row['submission'] }} · Version {{ $row['version']??'—' }} · {{ $row['account'] }}</span>@forelse($row['issues'] as $issue)<p class="text-sm text-red-800">{{ $issue }}</p>@empty<x-badge tone="success">Eligible</x-badge>@endforelse</div>@endforeach
@if(!collect($preview['rows'])->contains(fn($row)=>count($row['issues'])>0))
<form wire:submit="confirmReview"><x-confirm-action label="Confirm verification decision" title="Confirm this review?" message="This immutable decision changes exactly the reviewed submissions and records an audit trail and Rider notification." /></form>
@else<p role="alert" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-950">Resolve excluded rows and prepare a new selection. No decisions have been applied.</p>@endif</div>
@endif
@if($results)<div class="surface grid gap-3 p-5"><h2 class="font-semibold">Verification results</h2>@foreach($results as $result)<p class="break-all text-xs">{{ $result['submission'] }} · {{ str_replace('_',' ',$result['status']) }} · Review {{ $result['review'] }}</p>@endforeach</div>@endif

