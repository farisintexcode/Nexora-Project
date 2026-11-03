@props(['status'])
<span {{ $attributes->class(['badge border-transparent', match ($status) {
    'unstarted' => 'bg-slate-500 text-white',
    'in_progress' => 'bg-blue-600 text-white',
    'submitted' => 'bg-amber-400 text-slate-950',
    'completed' => 'bg-green-500 text-slate-950',
    'cancelled' => 'bg-red-600 text-white',
    default => 'bg-violet-600 text-white',
}]) }}>{{ ucfirst(str_replace('_', ' ', $status)) }}</span>
