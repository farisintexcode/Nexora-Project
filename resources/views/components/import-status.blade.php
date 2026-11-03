@props(['status'])
<span {{ $attributes->class(['badge border-transparent', match ($status) {
    'uploaded' => 'bg-slate-500 text-white',
    'scanning' => 'bg-cyan-400 text-slate-950',
    'mapping' => 'bg-violet-600 text-white',
    'validating' => 'bg-fuchsia-600 text-white',
    'ready' => 'bg-blue-600 text-white',
    'processing' => 'bg-amber-400 text-slate-950',
    'completed' => 'bg-green-500 text-slate-950',
    'failed' => 'bg-red-600 text-white',
    default => 'bg-slate-300 text-slate-950',
}]) }}>{{ ucfirst($status) }}</span>
