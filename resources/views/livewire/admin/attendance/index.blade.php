<section class="hr-page grid min-w-0 gap-6" wire:poll.30s>
<x-feedback /><x-page-header title="Attendance" eyebrow="People & operations" description="See who is working today, who is on leave, and every rider's month at a glance.">
<x-slot:actions>@can('configure',\App\Models\Attendance::class)<a class="btn btn-primary" href="{{ route('admin.attendance.configuration') }}">Add schedule & policies</a>@endcan</x-slot:actions>
</x-page-header>
@php($time = fn ($value) => $value ? \Carbon\CarbonImmutable::parse($value)->timezone(config('dcms.business_timezone'))->format('h:i A') : null)

<div class="flex flex-wrap items-end justify-between gap-3">
    <div class="inline-flex rounded-xl border border-ink-200 bg-white p-1" role="tablist" aria-label="Attendance views">
        @foreach(['today' => ['clock', 'Today'], 'calendar' => ['calendar', 'Calendar'], 'records' => ['layers', 'Records']] as $key => [$icon, $label])
            <button type="button" role="tab" aria-selected="{{ $view === $key ? 'true' : 'false' }}" wire:click="$set('view', '{{ $key }}')" @class(['inline-flex min-h-10 items-center gap-2 rounded-lg px-4 text-xs font-semibold transition-colors', 'bg-brand-primary text-white' => $view === $key, 'text-ink-600 hover:bg-brand-soft' => $view !== $key])><x-icon :name="$icon" class="size-4" />{{ $label }}</button>
        @endforeach
    </div>
    @if($view !== 'records')
        <div class="w-full sm:w-72"><x-select name="rider" label="Rider" wire:model.live="rider"><option value="">All riders</option>@foreach($riders as $option)<option value="{{ $option->public_id }}">{{ $option->staff_no }} - {{ $option->full_name }}</option>@endforeach</x-select></div>
    @endif
</div>
<div wire:loading.delay wire:target.except="$refresh" role="status" class="rounded-xl bg-brand-soft p-3 text-sm">Updating attendance…</div>

@if($view === 'today')
    @php($counts = collect($groups)->map->count())
    <section class="surface grid gap-5 p-5 sm:p-6" aria-labelledby="day-heading">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <button type="button" wire:click="shiftDay(-1)" class="grid size-10 place-items-center rounded-xl border border-ink-200 hover:bg-brand-soft" aria-label="Previous day"><x-icon name="arrow-left" class="size-4" /></button>
                <div class="px-2"><p class="eyebrow">{{ $isToday ? "Today's work" : 'Work on' }}</p><h2 id="day-heading" class="mt-1 text-lg font-semibold">{{ $boardDay->format('l, d M Y') }}</h2></div>
                <button type="button" wire:click="shiftDay(1)" class="grid size-10 place-items-center rounded-xl border border-ink-200 hover:bg-brand-soft" aria-label="Next day"><x-icon name="arrow-right" class="size-4" /></button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @unless($isToday)<button type="button" wire:click="$set('day', '')" class="btn btn-secondary btn-sm">Back to today</button>@endunless
                <button type="button" wire:click="$set('view', 'calendar')" class="btn btn-secondary btn-sm"><x-icon name="calendar" class="size-4" />Month calendar</button>
            </div>
        </div>
        @foreach($holidays as $holiday)<p class="flex items-center gap-2 rounded-xl bg-red-50 p-3 text-xs font-semibold text-red-800"><x-icon name="calendar" class="size-4" />Public holiday · {{ $holiday }}</p>@endforeach
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            @foreach(['working' => ['bg-brand-accent', 'clock'], 'done' => ['bg-green-600', 'check'], 'leave' => ['bg-sky-600', 'calendar'], 'waiting' => ['bg-red-600', 'info'], 'off' => ['bg-ink-400', 'moon']] as $key => [$dot, $icon])
                <a href="#group-{{ $key }}" class="rounded-xl border border-ink-200 p-3 transition-colors hover:bg-brand-soft/60"><span class="flex items-center gap-2 text-xs text-ink-600"><span class="size-2 rounded-full {{ $dot }}" aria-hidden="true"></span>{{ \App\Livewire\Admin\Attendance\Index::DAY_GROUPS[$key] }}</span><span @class(['mt-1 block text-2xl font-semibold tabular-nums', 'text-ink-400' => $counts[$key] === 0])>{{ $counts[$key] }}</span></a>
            @endforeach
        </div>
    </section>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach(['working' => 'bg-brand-accent', 'done' => 'bg-green-600', 'leave' => 'bg-sky-600', 'waiting' => 'bg-red-600'] as $key => $dot)
            <section id="group-{{ $key }}" class="surface grid min-w-0 content-start gap-3 p-4 scroll-mt-28" aria-labelledby="group-{{ $key }}-heading">
                <h3 id="group-{{ $key }}-heading" class="flex items-center justify-between gap-2 text-sm font-semibold"><span class="flex items-center gap-2"><span class="size-2.5 rounded-full {{ $dot }}" aria-hidden="true"></span>{{ \App\Livewire\Admin\Attendance\Index::DAY_GROUPS[$key] }}</span><span class="text-xs text-ink-500 tabular-nums">{{ $groups[$key]->count() }}</span></h3>
                @forelse($groups[$key] as $row)
                    @php($link = $row->schedule_public_id ? route('admin.attendance.show', $row->schedule_public_id) : null)
                    <{{ $link ? 'a' : 'div' }} @if($link) href="{{ $link }}" @endif wire:key="day-{{ $key }}-{{ $row->schedule_public_id ?? $row->attendance_public_id }}" class="grid gap-1.5 rounded-xl border border-ink-100 p-3 transition-colors hover:bg-brand-soft/50">
                        <span class="flex items-start justify-between gap-2"><span class="min-w-0"><span class="block truncate text-sm font-semibold">{{ $row->rider_name }}</span><span class="block text-[11px] text-ink-500">{{ $row->staff_no }}</span></span>
                            @if($key === 'working' && $row->result_status === 'incomplete')<x-badge tone="warning">Missing clock-out</x-badge>@elseif($row->is_late)<x-badge tone="warning">Late {{ $row->late_minutes }}m</x-badge>@endif
                        </span>
                        @if(in_array($key, ['working', 'done'], true))
                            <span class="flex flex-wrap gap-x-3 text-xs text-ink-600"><span>In <strong class="font-semibold text-brand-dark">{{ $time($row->raw_clock_in_at) }}</strong></span>@if($row->effective_clock_out_at)<span>Out <strong class="font-semibold text-brand-dark">{{ $time($row->effective_clock_out_at) }}</strong></span>@endif</span>
                        @elseif($key === 'waiting')
                            <span class="text-xs text-ink-600">@if($row->scheduled_start_at)Shift {{ $time($row->scheduled_start_at) }} – {{ $time($row->scheduled_end_at) }} · @endif<span @class(['font-semibold', 'text-red-700' => $row->result_status === 'absent'])>{{ $row->result_status === 'absent' ? 'Absent' : 'Not in yet' }}</span></span>
                        @else
                            <span class="text-xs text-ink-600">Approved leave</span>
                        @endif
                        @if($row->pending_leave)<span class="text-[11px] font-semibold text-sky-800">Leave request waiting for approval</span>@endif
                    </{{ $link ? 'a' : 'div' }}>
                @empty
                    <p class="rounded-xl border border-dashed border-ink-200 p-4 text-center text-xs text-ink-500">No riders</p>
                @endforelse
            </section>
        @endforeach
    </div>
    @if($groups['off']->isNotEmpty())
        <section id="group-off" class="surface p-4 scroll-mt-28"><h3 class="text-sm font-semibold">Rest day / holiday <span class="text-xs font-normal text-ink-500">· {{ $groups['off']->count() }}</span></h3><p class="mt-2 text-xs leading-6 text-ink-600">{{ $groups['off']->map(fn ($row) => $row->rider_name.' ('.(\App\Services\Attendance\AttendanceStatus::LABELS[$row->day_type] ?? 'Off').')')->join(', ') }}</p></section>
    @endif

@elseif($view === 'calendar')
    <section class="surface grid min-w-0 gap-4 p-4 sm:p-6" aria-labelledby="month-heading">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <button type="button" wire:click="shiftMonth(-1)" class="grid size-10 place-items-center rounded-xl border border-ink-200 hover:bg-brand-soft" aria-label="Previous month"><x-icon name="arrow-left" class="size-4" /></button>
                <h2 id="month-heading" class="min-w-40 px-2 text-center text-lg font-semibold">{{ $calendarMonth->format('F Y') }}</h2>
                <button type="button" wire:click="shiftMonth(1)" class="grid size-10 place-items-center rounded-xl border border-ink-200 hover:bg-brand-soft" aria-label="Next month"><x-icon name="arrow-right" class="size-4" /></button>
            </div>
            @php($riderStyles = ['absent' => ['bg-red-50 text-red-800', 'bg-red-600'], 'missing' => ['bg-amber-50 text-amber-900', 'bg-brand-accent'], 'working' => ['bg-brand-soft text-brand-dark', 'bg-brand-primary'], 'done' => ['bg-green-50 text-green-800', 'bg-green-600'], 'leave' => ['bg-sky-50 text-sky-800', 'bg-sky-600'], 'requested' => ['bg-white text-sky-800', 'bg-sky-300']])
            @php($describe = fn ($entry) => $entry->rider_name.' – '.\App\Livewire\Admin\Attendance\Index::CALENDAR_STATES[$entry->state].($entry->pending_leave && $entry->state !== 'requested' ? ', leave request waiting for approval' : ''))
            <ul class="flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-ink-600" aria-label="Legend">
                @foreach(\App\Livewire\Admin\Attendance\Index::CALENDAR_STATES as $state => $label)
                    <li class="flex items-center gap-1.5"><span @class(['size-2 rounded-full', $riderStyles[$state][1], 'outline-1 outline-offset-1 outline-dashed outline-sky-500' => $state === 'requested'])></span>{{ $label }}</li>
                @endforeach
                <li class="flex items-center gap-1.5"><span class="font-semibold text-red-700">Red date</span>Public holiday</li>
            </ul>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[42rem] table-fixed border-collapse text-left">
                <thead><tr>@foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)<th scope="col" @class(['pb-2 text-center text-[11px] font-semibold tracking-wide uppercase', 'text-red-700' => $weekday === 'Sun', 'text-ink-500' => $weekday !== 'Sun'])>{{ $weekday }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach($weeks as $week)
                    <tr>
                    @foreach($week as $date)
                        @php($key = $date->toDateString())
                        @php($inMonth = $date->month === $calendarMonth->month)
                        @php($entries = $inMonth ? $days->get($key, collect()) : collect())
                        @php($dayHolidays = $holidays->get($key, collect()))
                        @php($isToday = $key === now()->timezone(config('dcms.business_timezone'))->toDateString())
                        <td class="h-32 border border-ink-100 p-0 align-top">
                            <button type="button" wire:click="showDay('{{ $key }}')" @class(['flex h-full w-full flex-col gap-1 p-2 text-left transition-colors hover:bg-brand-soft/60', 'opacity-40' => ! $inMonth, 'bg-red-50' => $dayHolidays->isNotEmpty() && $inMonth]) aria-label="{{ $date->format('l, d F Y') }}{{ $dayHolidays->isNotEmpty() ? ', public holiday: '.$dayHolidays->join(', ') : '' }}{{ $entries->isNotEmpty() ? '. '.$entries->map($describe)->join('; ') : '' }}">
                                <span @class(['grid size-6 place-items-center rounded-full text-xs font-semibold', 'bg-brand-primary text-white' => $isToday, 'text-red-700' => ! $isToday && ($dayHolidays->isNotEmpty() || $date->isSunday())])>{{ $date->day }}</span>
                                @foreach($dayHolidays as $holiday)<span class="line-clamp-2 text-[10px] leading-3 font-semibold text-red-700" title="{{ $holiday }}">{{ $holiday }}</span>@endforeach
                                @if($entries->isNotEmpty())
                                    <span class="mt-auto grid min-w-0 gap-0.5">
                                        @foreach($entries->take(3) as $entry)
                                            @php($timeline = collect([$entry->raw_clock_in_at ? 'In '.$time($entry->raw_clock_in_at) : null, $entry->effective_clock_out_at ? 'Out '.$time($entry->effective_clock_out_at) : null])->filter()->join(' · '))
                                            <span @class(['flex min-w-0 items-center gap-1 rounded px-1 text-[10px] leading-4 font-semibold', $riderStyles[$entry->state][0], 'outline-1 -outline-offset-1 outline-dashed outline-sky-500' => $entry->pending_leave]) title="{{ $describe($entry) }} ({{ $entry->staff_no }}){{ $timeline ? ' · '.$timeline : '' }}"><span class="size-1.5 shrink-0 rounded-full {{ $riderStyles[$entry->state][1] }}" aria-hidden="true"></span><span class="truncate">{{ $entry->rider_name }}</span></span>
                                        @endforeach
                                        @if($entries->count() > 3)<span class="px-1 text-[10px] leading-4 font-semibold text-ink-500">+{{ $entries->count() - 3 }} more</span>@endif
                                    </span>
                                @endif
                            </button>
                        </td>
                    @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-xs text-ink-500">Each name is coloured by what that rider did. A dashed outline means the rider has a leave request waiting for approval. Hover a name for clock times, or click a date to see every rider that day. Public holidays shown are for the states your riders work in (all states when no rider has a work state set).</p>
    </section>

@else
    <x-filter-bar name="attendance" :count="(int)($search !== '') + (int)($rider !== '') + (int)($status !== '')">
    <x-date-range name="service-date" label="Service date" from="from" until="until" />
    <x-select name="rider" label="Rider" wire:model="rider"><option value="">All accessible Riders</option>@foreach($riders as $option)<option value="{{ $option->public_id }}">{{ $option->staff_no }} - {{ $option->full_name }}</option>@endforeach</x-select>
    <x-select name="status" label="Attendance status" wire:model="status"><option value="">All statuses</option>@foreach(\App\Services\Attendance\AttendanceStatus::LABELS as $key=>$text)<option value="{{ $key }}">{{ $text }}</option>@endforeach</x-select>
    <x-slot:advanced><x-input name="search" label="Rider name or staff number" wire:model="search" placeholder="Starts with..." /></x-slot:advanced>
    </x-filter-bar>
    @if($filterError)<p role="alert" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs">{{ $filterError }}</p>@endif
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6" role="group" aria-label="Filter by attendance status">@foreach(['present'=>['check','green'],'half_day'=>['clock','amber'],'absent'=>['close','red'],'incomplete'=>['activity','orange'],'leave'=>['calendar','sky'],'needs_review'=>['info','violet']] as $key=>[$icon,$tone])@php($isSelected = $status === $key)@php($count = (int) ($totals[$key] ?? 0))<button type="button" wire:key="status-card-{{ $key }}" class="status-card surface" style="--tone: var(--color-{{ $tone }}-600)" wire:click="selectStatus('{{ $key }}')" wire:loading.attr="disabled" wire:target="selectStatus" aria-pressed="{{ $isSelected ? 'true' : 'false' }}" title="{{ $isSelected ? 'Clear this filter' : 'Show only '.\App\Services\Attendance\AttendanceStatus::LABELS[$key] }}"><span class="flex items-center justify-between gap-2"><span class="text-xs font-medium text-ink-600">{{ \App\Services\Attendance\AttendanceStatus::LABELS[$key] }}</span><span class="status-chip"><x-icon :name="$icon" class="size-4" /></span></span><span @class(['text-2xl font-semibold tabular-nums', 'text-ink-400' => $count === 0])>{{ $count }}</span><span class="status-hint">@if($isSelected)<x-icon name="close" class="size-3.5" />Clear filter @else View records<x-icon name="arrow-right" class="size-3.5" />@endif</span></button>@endforeach</div>
    <div class="flex flex-wrap items-center justify-between gap-3"><p class="text-xs text-ink-500">Same filtered calendar and punch records · {{ $from }}–{{ $until }} · {{ $asOf->timezone(config('dcms.business_timezone'))->format('d M Y H:i:s') }} MYT</p>
    @if(!$filterError) @can('export',\App\Models\Attendance::class)<div class="flex flex-wrap gap-2"><a class="btn btn-secondary" href="{{ route('admin.attendance.export',['format'=>'csv']) }}">Download CSV</a><a class="btn btn-primary" href="{{ route('admin.attendance.export',['format'=>'print']) }}" target="_blank" rel="noopener">Download PDF</a></div>@endcan @endif</div>
    <x-attendance-rows :rows="$rows" admin />{{ $rows->links() }}
@endif
</section>
