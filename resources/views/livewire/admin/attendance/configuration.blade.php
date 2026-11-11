<section class="hr-page grid min-w-0 gap-6"><x-feedback />
<x-page-header title="Schedules & policies" eyebrow="Attendance configuration" description="Set working hours once and they apply to every Rider, every day. Public holidays follow the Malaysian calendar for each Rider's state."><x-slot:actions><a class="btn btn-secondary" href="{{ route('admin.attendance.index') }}"><x-icon name="arrow-left" class="size-4" />Back to attendance</a></x-slot:actions></x-page-header>
@php($dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'])

<section class="surface grid gap-4 p-5" aria-labelledby="policy-in-effect">
    <div class="flex items-center gap-3"><span class="icon-tile"><x-icon name="calendar" /></span><div><h2 id="policy-in-effect" class="text-lg font-semibold">Policy in effect</h2><p class="text-xs text-ink-500">Applies automatically to every active Rider, every day.</p></div></div>
    @if($currentPolicy)
        <div class="flex flex-wrap items-center gap-2"><x-badge tone="success">In effect</x-badge><span class="font-semibold">{{ $currentPolicy->code }} · version {{ $currentPolicy->version }}</span><span class="text-sm text-ink-500">since {{ $currentPolicy->effective_from->format('d M Y') }}</span></div>
        <p class="text-sm leading-6">{{ collect($currentPolicy->rules['workdays'])->sort()->map(fn ($day) => $dayNames[$day])->implode(', ') }} · {{ $currentPolicy->rules['shift_start'] }}–{{ $currentPolicy->rules['shift_end'] }} · {{ $currentPolicy->rules['break_minutes'] }}-minute unpaid break · {{ $currentPolicy->rules['grace_minutes'] }} minutes late allowed · Present after {{ round($currentPolicy->rules['present_minutes'] / 60, 1) }} h, Half Day after {{ round($currentPolicy->rules['half_day_minutes'] / 60, 1) }} h</p>
    @else
        <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6">No policy applies yet. Publish one below and every Rider's calendar fills in by itself from the start date.</p>
    @endif
    @foreach($upcomingPolicies as $upcoming)
        <p class="flex flex-wrap items-center gap-2 text-sm"><x-badge tone="brand">Scheduled</x-badge>{{ $upcoming->code }} · version {{ $upcoming->version }} takes over from {{ $upcoming->effective_from->format('d M Y') }}.</p>
    @endforeach
    <p class="field-help">Riders in Kedah, Kelantan and Terengganu automatically work Sunday to Thursday instead of Monday to Friday. Set each Rider's state on their staff record.</p>
</section>

<form wire:submit="publish" class="surface grid min-w-0 gap-5 p-5"><div><h2 class="text-lg font-semibold">{{ $currentPolicy ? 'Change the policy (publish a new version)' : 'Publish a policy' }}</h2><p class="mt-1 text-xs text-ink-500">Recommended starting setup (approved by the owner): Monday to Friday, 9:00 am to 6:00 pm, 1-hour unpaid break, up to 15 minutes late still counts as on time, Present after 7 hours and Half Day after 4 hours. A published version is saved under your name and can't be edited; publish a new version to change it.</p></div>
<x-input name="code" label="Policy name (code)" wire:model="code" required maxlength="64" />
<fieldset class="grid gap-3"><legend class="field-label mb-3">Working days</legend><div class="flex flex-wrap gap-3">@foreach([1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'] as $number=>$day)<label class="flex min-h-11 items-center gap-2 rounded-xl border border-ink-200 px-3 text-sm"><input type="checkbox" wire:model="rules.workdays" value="{{ $number }}" class="size-5 accent-brand-primary" />{{ $day }}</label>@endforeach</div></fieldset>
<div class="grid min-w-0 gap-4 sm:grid-cols-2"><x-input name="rules.shift_start" type="time" label="Shift start (MYT)" wire:model="rules.shift_start" required /><x-input name="rules.shift_end" type="time" label="Shift end (MYT)" wire:model="rules.shift_end" required /></div>
<div class="grid gap-4 sm:grid-cols-2"><x-input name="rules.break_minutes" type="number" label="Unpaid break minutes" wire:model="rules.break_minutes" min="0" max="720" required help="Taken off every completed shift." /><x-input name="rules.grace_minutes" type="number" label="Minutes late still on time" wire:model="rules.grace_minutes" min="0" max="240" required /><x-input name="rules.present_minutes" type="number" label="Minimum minutes for Present" wire:model="rules.present_minutes" min="1" max="1440" required /><x-input name="rules.half_day_minutes" type="number" label="Minimum minutes for Half Day" wire:model="rules.half_day_minutes" min="1" max="1439" required /></div>
<label class="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" wire:model="rules.overnight_allowed" class="size-5 accent-brand-primary" />Allow overnight shifts (end on the next day)</label>
<div class="grid gap-4 rounded-xl border border-ink-200 p-4 sm:grid-cols-2">
    <x-date-time name="effective-from" label="Start date" model="effectiveFrom" :with-time="false" :min="$today" placeholder="Choose the first day" />
    <p class="field-help self-end">Every Rider follows this version every day from this date, until you publish a newer one. Days already on the calendar (for example today, or dates in a leave request) keep the version they started with.</p>
</div>
<p class="text-xs text-ink-500">Below the Half Day minimum is Incomplete. Late is shown separately. Times are Malaysia time.</p>
<div class="hr-actions"><x-confirm-action label="Publish policy" title="Publish this policy?" message="A published version can't be edited. To change it later, publish a new version." /></div></form>

<section class="surface grid min-w-0 gap-5 p-5" aria-labelledby="public-holidays">
    <div class="flex flex-wrap items-start justify-between gap-3"><div class="flex items-center gap-3"><span class="icon-tile"><x-icon name="tag" /></span><div><h2 id="public-holidays" class="text-lg font-semibold">Malaysian public holidays</h2><p class="text-xs text-ink-500">National holidays apply to everyone. State holidays apply only to Riders whose work state matches.</p></div></div>
        <button type="button" class="btn btn-primary" wire:click="syncHolidays" wire:loading.attr="disabled" wire:target="syncHolidays" @disabled(! $syncConfigured)><x-icon name="calendar" class="size-4" /><span wire:loading.remove wire:target="syncHolidays">Update from online calendar</span><span wire:loading wire:target="syncHolidays">Updating…</span></button></div>
    <p class="text-xs leading-5 text-ink-500">Source: Malaysia Calendar API, based on the official JPM gazette. Updates automatically on the 1st of each month. Replacement holidays (cuti ganti) are worked out from each state's weekend: please check them against the gazette and click to ignore any that don't apply.</p>
    @unless($syncConfigured)<p class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5">Online updates are switched off because <code>MALAYSIA_CALENDAR_API_URL</code> is empty in the .env file. Add holidays by hand below.</p>@endunless
    @error('holidays')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
    @php($thisYear = (int) substr($today, 0, 4))
    <div class="grid items-end gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
        <x-select name="holidayYear" label="Year" wire:model.live="holidayYear">@foreach(range(min($holidayYearValue, $thisYear - 1), max($holidayYearValue, $thisYear + 1)) as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</x-select>
        <x-select name="holidayStateFilter" label="Show holidays for" wire:model.live="holidayStateFilter"><option value="">All states</option>@foreach($states as $state)<option value="{{ $state->code }}">{{ $state->label }}</option>@endforeach</x-select>
        <div class="inline-flex min-h-12 rounded-xl border border-ink-200 p-1" role="group" aria-label="Holiday view">@foreach(['calendar' => ['Calendar', 'calendar'], 'list' => ['List', 'menu']] as $mode => [$modeLabel, $modeIcon])<button type="button" wire:click="$set('holidayView', '{{ $mode }}')" aria-pressed="{{ $holidayView === $mode ? 'true' : 'false' }}" @class(['inline-flex items-center gap-2 rounded-lg px-3 text-sm font-semibold transition', 'bg-brand-primary text-white' => $holidayView === $mode, 'text-ink-700 hover:bg-ink-50' => $holidayView !== $mode])><x-icon :name="$modeIcon" class="size-4" />{{ $modeLabel }}</button>@endforeach</div>
    </div>
    @php($chipClass = fn (array $holiday): string => match (true) {
        ! $holiday['active'] => 'bg-ink-100 text-ink-500 line-through',
        $holiday['replacement'] => 'border border-dashed border-brand-accent bg-white text-ink-800',
        $holiday['national'] => 'bg-brand-primary text-white',
        default => 'bg-brand-accent-soft text-brand-dark ring-1 ring-inset ring-brand-accent/40',
    })
    @php($appliesTo = fn (array $holiday): string => $holiday['national'] ? 'All Malaysia' : implode(', ', $holiday['states']))
    @if($holidayView === 'calendar')
        @php($shownMonth = \Carbon\CarbonImmutable::create($holidayYearValue, $holidayMonthValue, 1))
        @php($weekendLabel = in_array($holidayStateFilter, config('attendance.friday_weekend_states'), true) ? 'Fri–Sat weekend' : 'Sat–Sun weekend')
        <div class="overflow-hidden rounded-2xl border border-ink-200" wire:loading.class="opacity-60" wire:target="shiftMonth,showThisMonth,holidayYear,holidayStateFilter">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-200 bg-brand-soft px-3 py-3 sm:px-4">
                <div class="flex items-center gap-1"><button type="button" class="page-control" wire:click="shiftMonth(-1)" aria-label="Previous month"><x-icon name="arrow-left" class="size-4" /></button><h3 class="min-w-36 text-center text-base font-semibold tabular-nums" aria-live="polite">{{ $shownMonth->format('F Y') }}</h3><button type="button" class="page-control" wire:click="shiftMonth(1)" aria-label="Next month"><x-icon name="arrow-right" class="size-4" /></button></div>
                <div class="flex items-center gap-3"><span class="text-xs text-ink-600">{{ $monthHolidays->where('active', true)->count() }} {{ \Illuminate\Support\Str::plural('holiday', $monthHolidays->where('active', true)->count()) }} this month</span>@if($shownMonth->format('Y-n') !== \Carbon\CarbonImmutable::parse($today)->format('Y-n'))<button type="button" class="btn btn-secondary min-h-9 py-1 text-xs" wire:click="showThisMonth">Today</button>@endif</div>
            </div>
            <div class="grid grid-cols-7 border-b border-ink-200 text-center text-[10px] font-semibold tracking-widest text-ink-600 uppercase">@foreach($calendarWeeks[0] as $day)<div @class(['py-2', 'bg-ink-50' => $day['isWeekend']])><span class="sm:hidden">{{ $day['date']->format('D')[0] }}</span><span class="hidden sm:inline">{{ $day['date']->format('D') }}</span></div>@endforeach</div>
            <div class="grid grid-cols-7">
                @foreach($calendarWeeks as $week)
                    @foreach($week as $day)
                        @php($activeHolidays = array_filter($day['holidays'], fn (array $holiday): bool => $holiday['active']))
                        <div wire:key="cal-{{ $day['date']->toDateString() }}" @class(['min-h-16 border-b border-r border-ink-100 p-1 sm:min-h-28 sm:p-1.5 [&:nth-child(7n)]:border-r-0', 'bg-ink-50' => $day['isWeekend'] && $day['inMonth'], 'bg-white' => ! $day['isWeekend'] && $day['inMonth'], 'bg-ink-50/50 text-ink-400' => ! $day['inMonth']]) @if($day['holidays']) title="{{ collect($day['holidays'])->map(fn ($holiday) => $holiday['name'].' — '.$appliesTo($holiday).($holiday['active'] ? '' : ' (ignored)'))->implode("\n") }}" @endif>
                            <div class="flex items-center justify-between"><span @class(['grid size-6 place-items-center rounded-full text-xs tabular-nums', 'bg-brand-accent font-bold text-brand-dark' => $day['isToday'], 'font-semibold text-brand-primary' => ! $day['isToday'] && $activeHolidays && $day['inMonth'], 'font-medium' => ! $day['isToday'] && ! $activeHolidays])>{{ $day['date']->day }}</span>@if($day['holidays'])<span class="flex gap-0.5 sm:hidden" aria-hidden="true">@foreach(array_slice($day['holidays'], 0, 3) as $holiday)<span class="size-1.5 rounded-full {{ $holiday['active'] ? ($holiday['national'] ? 'bg-brand-primary' : 'bg-brand-accent') : 'bg-ink-300' }}"></span>@endforeach</span>@endif</div>
                            @if($day['holidays'] && $day['inMonth'])
                                <ul class="mt-1 hidden gap-1 sm:grid">
                                    @foreach(array_slice($day['holidays'], 0, 3) as $holiday)<li class="truncate rounded-md px-1.5 py-0.5 text-[11px] leading-4 font-medium {{ $chipClass($holiday) }}">@if($holiday['tentative'])<span title="Date not final">~</span> @endif{{ $holiday['replacement'] ? 'Ganti: '.\Illuminate\Support\Str::before($holiday['name'], ' (replacement holiday)') : $holiday['name'] }}</li>@endforeach
                                    @if(count($day['holidays']) > 3)<li class="px-1 text-[10px] text-ink-500">+{{ count($day['holidays']) - 3 }} more</li>@endif
                                </ul>
                            @endif
                        </div>
                    @endforeach
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-ink-200 px-3 py-3 text-[11px] text-ink-600 sm:px-4">
                <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-5 rounded bg-brand-primary"></span>All Malaysia</span>
                <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-5 rounded bg-brand-accent-soft ring-1 ring-inset ring-brand-accent/40"></span>Some states only</span>
                <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-5 rounded border border-dashed border-brand-accent bg-white"></span>Cuti ganti</span>
                <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-5 rounded bg-ink-100"></span><span class="line-through">Ignored</span></span>
                <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-5 rounded border border-ink-200 bg-ink-50"></span>{{ $weekendLabel }}</span>
                <span class="inline-flex items-center gap-1.5">~ Date not final</span>
            </div>
        </div>

        <div class="grid gap-2">
            <h3 class="text-sm font-semibold">Holidays in {{ $shownMonth->format('F') }}</h3>
            @forelse($monthHolidays as $holiday)
                <article wire:key="agenda-{{ $holiday['ids'][0] }}" @class(['flex items-start gap-3 rounded-xl border border-ink-200 p-3', 'opacity-60' => ! $holiday['active']])>
                    <div class="grid w-12 shrink-0 place-items-center rounded-lg bg-brand-soft py-1.5 text-center"><span class="text-lg leading-none font-semibold tabular-nums">{{ $holiday['date']->format('j') }}</span><span class="mt-0.5 text-[10px] font-semibold text-ink-600 uppercase">{{ $holiday['date']->format('D') }}</span></div>
                    <div class="min-w-0 flex-1"><p @class(['font-semibold', 'line-through' => ! $holiday['active']])>{{ $holiday['name'] }}</p><p class="mt-0.5 text-xs leading-5 text-ink-600">{{ $appliesTo($holiday) }}</p>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">@if($holiday['tentative'])<x-badge tone="neutral" title="Islamic dates depend on moon sighting and may move; the next update corrects them.">Date not final</x-badge>@endif @if($holiday['replacement'])<x-badge tone="neutral">Cuti ganti</x-badge>@endif @if($holiday['manual'])<span class="text-xs text-ink-500">Added by hand</span>@endif</div></div>
                    <button type="button" class="btn btn-secondary min-h-9 shrink-0 py-1 text-xs" wire:click="toggleHoliday(@js($holiday['ids']), {{ $holiday['active'] ? 'false' : 'true' }})">{{ $holiday['active'] ? 'Ignore' : 'Use again' }}</button>
                </article>
            @empty
                <p class="rounded-xl bg-ink-50 p-4 text-sm text-ink-600">{{ $holidays->isEmpty() ? ($syncConfigured ? 'No holidays for '.$holidayYearValue.' yet. Click Update from online calendar to download them.' : 'No holidays for '.$holidayYearValue.' yet. Add them by hand below.') : 'No public holidays in '.$shownMonth->format('F').'.' }}</p>
            @endforelse
        </div>
    @elseif($holidays->isEmpty())
        <x-empty-state icon="calendar" title="No holidays for {{ $holidayYearValue }} yet" message="{{ $syncConfigured ? 'Click Update from online calendar to download this year’s national and state holidays.' : 'Add holidays by hand below.' }}" />
    @else
        <div class="overflow-x-auto"><table class="data-table"><caption class="sr-only">Public holidays {{ $holidayYearValue }}</caption><thead><tr>@foreach(['Date', 'Holiday', 'Applies to', 'Status'] as $heading)<th scope="col">{{ $heading }}</th>@endforeach</tr></thead><tbody>
        @foreach($holidays as $holiday)<tr wire:key="holiday-{{ $holiday['ids'][0] }}" @class(['opacity-60' => ! $holiday['active']])><td class="whitespace-nowrap"><span class="font-semibold">{{ $holiday['date']->format('d M') }}</span><p class="mt-1 text-xs text-ink-500">{{ $holiday['date']->format('l') }}</p></td><td>{{ $holiday['name'] }}<div class="mt-1 flex flex-wrap gap-1.5">@if($holiday['tentative'])<x-badge tone="neutral" title="Islamic dates depend on moon sighting and may move; the next update corrects them.">Date not final</x-badge>@endif @if($holiday['replacement'])<x-badge tone="neutral">Cuti ganti</x-badge>@endif @if($holiday['manual'])<span class="text-xs text-ink-500">Added by hand</span>@endif</div></td><td>@if($holiday['national'])<x-badge tone="brand">All Malaysia</x-badge>@else<span class="text-xs leading-5">{{ implode(', ', $holiday['states']) }}</span>@endif</td><td><button type="button" @class(['badge', 'border-green-200 bg-green-50 text-green-800' => $holiday['active'], 'border-ink-200 bg-ink-50 text-ink-600' => ! $holiday['active']]) wire:click="toggleHoliday(@js($holiday['ids']), {{ $holiday['active'] ? 'false' : 'true' }})" title="{{ $holiday['active'] ? 'Click to stop treating this as a holiday' : 'Click to treat this as a holiday again' }}">{{ $holiday['active'] ? 'Holiday' : 'Ignored' }}</button></td></tr>@endforeach
        </tbody></table></div>
    @endif
    <form wire:submit="addHoliday" class="grid gap-4 border-t border-ink-100 pt-5"><h3 class="font-semibold">Add a holiday by hand</h3><p class="-mt-2 text-xs text-ink-500">For special holidays announced at short notice (cuti peristiwa) or replacement days.</p>
        <div class="grid gap-4 sm:grid-cols-3"><x-date-time name="holiday-date" label="Date" model="holidayDate" :with-time="false" placeholder="Select a date" /><x-input name="holidayName" label="Holiday name" wire:model="holidayName" maxlength="150" required /><x-select name="holidayState" label="Applies to" wire:model="holidayState"><option value="">All Malaysia</option>@foreach($states as $state)<option value="{{ $state->code }}">{{ $state->label }} only</option>@endforeach</x-select></div>
        <div class="hr-actions"><button type="submit" class="btn btn-secondary" wire:loading.attr="disabled"><x-icon name="plus" class="size-4" />Add holiday</button></div></form>
    <p class="field-help">Holiday changes apply to days not yet on a Rider's calendar. A day that has already started keeps its type; fix it with an attendance correction.</p>
</section>
</section>
