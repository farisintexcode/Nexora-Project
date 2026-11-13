@php($periods = ['1_hour' => '1h', '6_hours' => '6h', '24_hours' => '24h', '7_days' => '7d'])
<div class="flex items-center gap-1 rounded-xl bg-white/10 p-1" role="group" aria-label="Time period"
    x-data="{
        setPeriod(period) {
            let query = new URLSearchParams(window.location.search)
            if (period === '1_hour') {
                query.delete('period')
            } else {
                query.set('period', period)
            }

            window.location = `${location.pathname}?${query}`
        }
    }"
>
    @foreach ($periods as $value => $label)
        <button type="button" @click="setPeriod('{{ $value }}')" aria-pressed="{{ $period === $value ? 'true' : 'false' }}" @class([
            'min-h-9 min-w-10 rounded-lg px-2 text-xs font-semibold transition-colors sm:text-sm',
            'bg-brand-accent text-brand-primary' => $period === $value,
            'text-white/70 hover:bg-white/10 hover:text-white' => $period !== $value,
        ])>{{ $label }}</button>
    @endforeach
</div>
