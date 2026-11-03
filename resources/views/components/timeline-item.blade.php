@props(['first' => false])
<li {{ $attributes->class(['relative pl-6']) }}><span @class(['absolute top-1 -left-[9px] size-4 rounded-full border-2 border-white', 'bg-brand-accent' => $first, 'bg-brand-primary' => !$first]) aria-hidden="true"></span>{{ $slot }}</li>
