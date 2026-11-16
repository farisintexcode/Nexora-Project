<section class="hr-page grid min-w-0 gap-6" wire:poll.30s>
<x-feedback /><x-page-header title="Attendance" eyebrow="People & operations" description="See who is working today, who is on leave, and every rider's month at a glance.">
<x-slot:actions>@can('configure',\App\Models\Attendance::class)<a class="btn btn-primary" href="{{ route('admin.attendance.configuration') }}">Add schedule & policies</a>@endcan</x-slot:actions>
</x-page-header>
@php($time = fn ($value) => $value ? \Carbon\CarbonImmutable::parse($value)->timezone(config('dcms.business_timezone'))->format('h:i A') : null)

<div class="flex flex-wrap items-end justify-between gap-3">
    <div class="inline-flex rounded-xl border border-ink-200 bg-white p-1" role="tablist" aria-label="Attendance views">
        @foreach(['calendar' => ['calendar', 'Calendar'], 'records' => ['layers', 'Records']] as $key => [$icon, $label])
            <button type="button" role="tab" aria-selected="{{ $view === $key ? 'true' : 'false' }}" wire:click="$set('view', '{{ $key }}')" @class(['inline-flex min-h-10 items-center gap-2 rounded-lg px-4 text-xs font-semibold transition-colors', 'bg-brand-primary text-white' => $view === $key, 'text-ink-600 hover:bg-brand-soft' => $view !== $key])><x-icon :name="$icon" class="size-4" />{{ $label }}</button>
        @endforeach
    </div>
</div>
<div wire:loading.delay wire:target.except="$refresh" role="status" class="rounded-xl bg-brand-soft p-3 text-sm">Updating attendance…</div>

@if($view === 'calendar')
    @php($selectedKey = $selectedDay->toDateString())
    @php($todayKey = now()->timezone(config('dcms.business_timezone'))->toDateString())
    @php($selectedHolidays = $holidays->get($selectedKey, collect()))
    @php($selectedCount = collect($selectedGroups)->sum(fn ($rows) => $rows->count()))
    @php($stateDots = ['absent' => 'bg-red-600', 'missing' => 'bg-brand-accent', 'working' => 'bg-brand-primary', 'done' => 'bg-green-600', 'leave' => 'bg-sky-600', 'requested' => 'bg-sky-300'])
    @php($groupDots = ['working' => 'bg-brand-accent', 'done' => 'bg-green-600', 'leave' => 'bg-sky-600', 'waiting' => 'bg-red-600', 'off' => 'bg-ink-400'])
    @php($describe = fn ($entry) => $entry->rider_name.' – '.\App\Livewire\Admin\Attendance\Index::CALENDAR_STATES[$entry->state].($entry->pending_leave && $entry->state !== 'requested' ? ', leave request waiting for approval' : ''))
    <div class="grid min-w-0 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,21rem)]">

    {{-- Day activity: the main area, left on wide screens. One compact table so a large rider list stays scannable. --}}
    <section class="order-2 grid min-w-0 content-start gap-4 xl:order-1" aria-labelledby="selected-day-heading" wire:key="day-activity-{{ $selectedKey }}">
        <div class="surface grid gap-5 p-5 sm:p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="eyebrow">{{ $selectedKey === $todayKey ? "Today's activity" : 'Activity on' }}</p>
                    <h2 id="selected-day-heading" class="mt-1 text-2xl font-semibold tracking-tight">{{ $selectedDay->format('l') }} <span class="font-normal text-ink-500">· {{ $selectedDay->format('d F Y') }}</span></h2>
                    <p class="mt-1 text-xs text-ink-500">{{ $selectedCount }} {{ \Illuminate\Support\Str::plural('rider', $selectedCount) }} on the calendar this day · times in MYT</p>
                </div>
                <div class="w-full sm:w-72"><x-input name="day-search" type="search" label="Find a rider" wire:model.live.debounce.300ms="daySearch" placeholder="Name or staff ID" /></div>
            </div>
            @foreach($selectedHolidays as $holiday)<p class="flex items-center gap-2 rounded-xl bg-red-50 p-3 text-xs font-semibold text-red-800"><x-icon name="calendar" class="size-4" />Public holiday · {{ $holiday }}</p>@endforeach
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 2xl:grid-cols-6" role="group" aria-label="Show riders by status">
                @foreach(['all' => ['All riders', 'bg-brand-dark', $selectedCount], ...collect(\App\Livewire\Admin\Attendance\Index::DAY_GROUPS)->map(fn ($label, $key) => [$label, $groupDots[$key], $selectedGroups[$key]->count()])->all()] as $key => [$label, $dot, $count])
                    @php($isActive = $dayGroup === $key || ($key === 'all' && ! array_key_exists($dayGroup, \App\Livewire\Admin\Attendance\Index::DAY_GROUPS)))
                    <button type="button" wire:click="showGroup('{{ $key }}')" aria-pressed="{{ $isActive ? 'true' : 'false' }}" @class(['rounded-xl border p-3 text-left transition-colors', 'border-brand-primary bg-brand-soft ring-1 ring-brand-primary' => $isActive, 'border-ink-200 hover:bg-brand-soft/60' => ! $isActive])>
                        <span class="flex items-center gap-2 text-xs text-ink-600"><span class="size-2 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span><span class="truncate">{{ $label }}</span></span>
                        <span @class(['mt-1 block text-2xl font-semibold tabular-nums', 'text-ink-400' => $count === 0])>{{ $count }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        @if($dayRows->isEmpty())
            @if($selectedCount === 0)
                <x-empty-state title="No activity on this day" message="No rider clocked in, took leave or had a schedule on this date. Choose another date in the calendar." icon="calendar" />
            @else
                <x-empty-state title="No riders match" message="Try another status or clear the search to see every rider on this day." icon="search" />
            @endif
        @else
            {{-- Wide screens: table. --}}
            <div class="surface hidden overflow-x-auto md:block">
                <table class="data-table">
                    <caption class="sr-only">Rider attendance on {{ $selectedDay->format('l, d F Y') }}</caption>
                    <thead><tr>@foreach(['Rider', 'Staff ID', 'Status', 'Clock in', 'Clock out', ''] as $heading)<th scope="col">{{ $heading }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach($dayRows as $row)
                        @php($link = $row->schedule_public_id ? route('admin.attendance.show', $row->schedule_public_id) : null)
                        <tr wire:key="day-row-{{ $row->schedule_public_id ?? $row->attendance_public_id ?? $row->rider_public_id }}">
                            <td class="font-semibold">{{ $row->rider_name }}@if($row->pending_leave)<span class="mt-0.5 block text-[11px] font-semibold text-sky-800">Leave request pending</span>@endif</td>
                            <td class="text-ink-600">{{ $row->staff_no }}</td>
                            <td><span class="flex flex-wrap gap-1.5"><x-badge :tone="$row->status_tone">{{ $row->status_label }}</x-badge>@if($row->is_late)<x-badge tone="warning">Late {{ $row->late_minutes }}m</x-badge>@endif</span></td>
                            <td class="tabular-nums">{{ $time($row->raw_clock_in_at) ?? '—' }}</td>
                            <td class="tabular-nums">{{ $time($row->effective_clock_out_at) ?? '—' }}</td>
                            <td class="text-right">@if($link)<a class="btn btn-secondary btn-sm" href="{{ $link }}">View</a>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{-- Phones: one line per rider. --}}
            <ul class="surface divide-y divide-ink-100 md:hidden">
                @foreach($dayRows as $row)
                    @php($link = $row->schedule_public_id ? route('admin.attendance.show', $row->schedule_public_id) : null)
                    <li wire:key="day-item-{{ $row->schedule_public_id ?? $row->attendance_public_id ?? $row->rider_public_id }}">
                        <{{ $link ? 'a' : 'div' }} @if($link) href="{{ $link }}" @endif class="flex items-center justify-between gap-3 p-4">
                            <span class="min-w-0"><span class="block truncate text-sm font-semibold">{{ $row->rider_name }}</span><span class="block text-[11px] text-ink-500">{{ $row->staff_no }}@if($row->raw_clock_in_at) · In {{ $time($row->raw_clock_in_at) }}@endif @if($row->effective_clock_out_at) · Out {{ $time($row->effective_clock_out_at) }}@endif</span></span>
                            <span class="flex shrink-0 flex-col items-end gap-1"><x-badge :tone="$row->status_tone">{{ $row->status_label }}</x-badge>@if($row->is_late)<x-badge tone="warning">Late {{ $row->late_minutes }}m</x-badge>@endif</span>
                        </{{ $link ? 'a' : 'div' }}>
                    </li>
                @endforeach
            </ul>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-ink-500">Showing {{ $dayRows->firstItem() }}–{{ $dayRows->lastItem() }} of {{ $dayRows->total() }} · riders needing attention first</p>
                {{ $dayRows->links() }}
            </div>
        @endif
    </section>

    {{-- Compact month picker: right on wide screens, first on small screens. --}}
    <aside class="surface order-1 grid min-w-0 gap-4 p-5 xl:sticky xl:top-24 xl:order-2" aria-labelledby="month-heading">
        <div class="flex items-center justify-between gap-2">
            <button type="button" wire:click="shiftMonth(-1)" class="grid size-8 place-items-center rounded-lg border border-ink-200 hover:bg-brand-soft" aria-label="Previous month"><x-icon name="arrow-left" class="size-4" /></button>
            <h2 id="month-heading" class="text-sm font-semibold">{{ $calendarMonth->format('F Y') }}</h2>
            <button type="button" wire:click="shiftMonth(1)" class="grid size-8 place-items-center rounded-lg border border-ink-200 hover:bg-brand-soft" aria-label="Next month"><x-icon name="arrow-right" class="size-4" /></button>
        </div>
        <div class="flex items-center justify-between gap-1" role="group" aria-label="Choose month">
            @foreach(range(-2, 2) as $offset)
                @php($tab = $calendarMonth->addMonthsNoOverflow($offset))
                <button type="button" wire:click="selectMonth('{{ $tab->format('Y-m') }}')" aria-pressed="{{ $offset === 0 ? 'true' : 'false' }}" title="{{ $tab->format('F Y') }}" @class(['rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide transition-colors', 'bg-brand-primary text-white' => $offset === 0, 'text-ink-500 hover:bg-brand-soft' => $offset !== 0])>{{ $tab->format('M') }}</button>
            @endforeach
        </div>
        <table class="w-full table-fixed border-collapse">
            <thead><tr>@foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)<th scope="col" @class(['pb-2 text-center text-[10px] font-semibold tracking-wide uppercase', 'text-red-700' => $weekday === 'Sun', 'text-ink-500' => $weekday !== 'Sun'])>{{ $weekday }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach($weeks as $week)
                <tr>
                @foreach($week as $date)
                    @php($key = $date->toDateString())
                    @php($inMonth = $date->month === $calendarMonth->month)
                    @php($entries = $inMonth ? $days->get($key, collect()) : collect())
                    @php($dayHolidays = $holidays->get($key, collect()))
                    @php($isSelected = $inMonth && $key === $selectedKey)
                    @php($states = $entries->pluck('state')->unique()->values())
                    <td class="p-0.5 text-center align-top">
                        <button type="button" wire:click="selectDay('{{ $key }}')" aria-pressed="{{ $isSelected ? 'true' : 'false' }}" aria-label="{{ $date->format('l, d F Y') }}{{ $dayHolidays->isNotEmpty() ? ', public holiday: '.$dayHolidays->join(', ') : '' }}{{ $entries->isNotEmpty() ? '. '.$entries->map($describe)->join('; ') : '' }}" title="{{ $dayHolidays->join(', ') }}" @class(['mx-auto grid w-full max-w-11 justify-items-center gap-1 rounded-xl py-1 transition-colors hover:bg-brand-soft/60', 'opacity-35' => ! $inMonth])>
                            <span @class([
                                'grid size-8 place-items-center rounded-full text-xs font-semibold tabular-nums',
                                'bg-brand-primary text-white' => $isSelected,
                                'ring-2 ring-brand-accent' => $key === $todayKey && ! $isSelected,
                                'text-red-700' => ! $isSelected && ($dayHolidays->isNotEmpty() || $date->isSunday()),
                            ])>{{ $date->day }}</span>
                            <span class="flex h-1.5 items-center gap-0.5" aria-hidden="true">@foreach($states->take(4) as $state)<span class="size-1.5 rounded-full {{ $stateDots[$state] }}"></span>@endforeach</span>
                        </button>
                    </td>
                @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
        @if($selectedKey !== $todayKey)<button type="button" wire:click="selectDay('{{ $todayKey }}')" class="btn btn-secondary btn-sm w-full">Go to today</button>@endif
        <ul class="grid grid-cols-2 gap-x-3 gap-y-1.5 border-t border-ink-100 pt-4 text-[11px] text-ink-600" aria-label="Legend">
            @foreach(\App\Livewire\Admin\Attendance\Index::CALENDAR_STATES as $state => $label)
                <li class="flex items-center gap-1.5"><span class="size-2 rounded-full {{ $stateDots[$state] }}" aria-hidden="true"></span>{{ $label }}</li>
            @endforeach
            <li class="flex items-center gap-1.5"><span class="font-semibold text-red-700">Red date</span>Public holiday</li>
            <li class="flex items-center gap-1.5"><span class="size-3 rounded-full ring-2 ring-brand-accent" aria-hidden="true"></span>Today</li>
        </ul>
    </aside>
    </div>

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
