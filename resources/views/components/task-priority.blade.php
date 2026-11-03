@props(['priority'])
<span {{ $attributes->class(['badge border-transparent text-slate-900', match ($priority) {
    'low' => 'bg-sky-100',
    'high' => 'bg-orange-100',
    'urgent' => 'bg-rose-200',
    default => 'bg-emerald-100',
}]) }}>{{ ucfirst($priority) }}</span>
