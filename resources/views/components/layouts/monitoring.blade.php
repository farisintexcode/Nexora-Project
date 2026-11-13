<!DOCTYPE html>
<html lang="en">
<head>
    {!! \Laravel\Pulse\Facades\Pulse::css() !!}
    @include('partials.head', ['title' => 'System health'])
    {!! \Laravel\Pulse\Facades\Pulse::js() !!}
    @livewireScriptConfig
</head>
<body class="min-h-dvh bg-white font-sans text-brand-dark antialiased">
    <a href="#main-content" class="sr-only focus:not-sr-only">Skip to content</a>
    <header class="monitoring-header sticky top-0 z-30 border-t-2 border-t-brand-accent bg-brand-primary text-white shadow-sm">
        <div class="mx-auto flex h-20 max-w-7xl items-center justify-between gap-4 px-4 sm:px-5">
            <div class="flex min-w-0 items-center gap-3">
                <a href="{{ route('admin.home') }}" class="shrink-0" aria-label="Nexora admin home"><x-brand /></a>
                <span class="hidden h-8 w-px bg-white/15 sm:block" aria-hidden="true"></span>
                <div class="hidden min-w-0 sm:block">
                    <p class="truncate text-xs font-semibold text-white">System health</p>
                    <p class="mt-1 text-[11px] text-white/60">Nexora · Admin</p>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-3 sm:gap-5">
                <livewire:pulse.period-selector />
                <span class="hidden h-5 w-px bg-white/15 sm:block" aria-hidden="true"></span>
                <a href="{{ route('admin.home') }}" class="flex min-h-11 items-center gap-2 rounded-xl border border-white/20 px-3 text-xs font-semibold text-white transition-colors hover:bg-white/10">
                    <x-icon name="arrow-left" class="size-4" />
                    <span class="hidden sm:inline">Admin workspace</span><span class="sm:hidden">Back</span>
                </a>
            </div>
        </div>
    </header>
    <main id="main-content" class="mx-auto max-w-7xl px-4 py-8 sm:px-5">
        <div class="mb-6">
            <p class="eyebrow">Monitoring</p>
            <h1 class="page-heading mt-2">System health</h1>
            <p class="mt-2 text-sm text-ink-500">Server load, slow requests, queues and errors.</p>
        </div>
        <div class="grid default:grid-cols-12 default:gap-6">{{ $slot }}</div>
    </main>
</body>
</html>
