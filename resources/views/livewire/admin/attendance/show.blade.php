@php
    $tz = config('dcms.business_timezone');
    $serviceDate = $schedule->service_date->toDateString();
    $labels = \App\Services\Attendance\AttendanceStatus::LABELS;
    $dayLabel = fn (?string $value): string => $value === 'scheduled' ? 'Working day' : ($labels[$value] ?? ($value ? ucfirst(str_replace('_', ' ', $value)) : '—'));
    $clock = function ($value) use ($tz, $serviceDate): ?string {
        if (! $value) {
            return null;
        }
        $time = \Carbon\CarbonImmutable::parse($value)->timezone($tz);

        return $time->format('H:i').($time->toDateString() !== $serviceDate ? ' · '.$time->format('d M') : '');
    };
    $sameInstant = fn ($first, $second): bool => ($first === null && $second === null) || ($first !== null && $second !== null && \Carbon\CarbonImmutable::parse($first)->equalTo(\Carbon\CarbonImmutable::parse($second)));
    $statusTone = match ($status) { 'present' => 'success', 'absent' => 'danger', 'half_day', 'incomplete', 'needs_review' => 'warning', 'leave', 'open', 'expected' => 'brand', default => 'neutral' };
    $duration = fn (?int $minutes): string => \App\Livewire\Admin\Attendance\Show::duration($minutes);
@endphp
<section class="hr-page grid min-w-0 gap-6"><x-feedback />
<x-page-header :title="$schedule->rider->full_name" :eyebrow="'Attendance · '.$schedule->rider->staff_no" :description="$schedule->service_date->format('l, d M Y').' · Times in '.$tz"><x-slot:actions>@can('view', $schedule->rider)<a class="btn btn-secondary" href="{{ route('admin.staff.show', ['staff' => $schedule->rider->public_id]) }}"><x-icon name="user" />Staff profile</a>@endcan<a class="btn btn-secondary" href="{{ route('admin.attendance.index') }}"><x-icon name="arrow-left" />Back to attendance</a></x-slot:actions></x-page-header>

<div class="flex flex-wrap items-center gap-2"><x-badge :tone="$statusTone">{{ $labels[$status] ?? 'Needs review' }}</x-badge>@if($attendance?->is_late)<x-badge tone="warning">Late · {{ $duration($attendance->late_minutes) }}</x-badge>@endif<span class="text-xs text-ink-500">{{ $dayLabel($schedule->day_type) }} · Policy {{ $schedule->policy->code }} v{{ $schedule->policy->version }}</span></div>
@if($leave)
<div role="status" @class(['flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 text-sm', 'border-brand-primary/40 bg-brand-soft' => $leave->status === 'approved', 'border-amber-200 bg-amber-50' => $leave->status === 'pending'])><span class="flex items-start gap-2"><x-icon name="calendar" class="mt-0.5 size-4" /><span><strong>{{ $leave->status === 'approved' ? 'On approved leave' : 'Leave requested — waiting for approval' }}</strong> · {{ $leave->type_snapshot['name'] ?? 'Leave' }}, {{ $leave->start_date->format('d M') }}{{ $leave->start_date->equalTo($leave->end_date) ? '' : ' – '.$leave->end_date->format('d M Y') }} ({{ $leave->total_days }} working {{ $leave->total_days === 1 ? 'day' : 'days' }})<span class="block text-xs text-ink-600">{{ $leaveCharged ? 'This day is counted as leave.' : 'This day is not counted as leave (rest day or public holiday).' }}</span></span></span><a class="btn btn-secondary btn-sm" href="{{ route('admin.leave.show', $leave->public_id) }}"><x-icon name="eye" />View leave request</a></div>
@endif

<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div class="surface p-4"><p class="text-xs text-ink-500">Worked time</p><p class="mt-2 text-2xl font-semibold tabular-nums">{{ $attendance?->worked_minutes !== null ? $duration($attendance->worked_minutes) : 'Pending' }}</p><p class="mt-1 text-[11px] text-ink-500">After unpaid break</p></div>
    <div class="surface p-4"><p class="text-xs text-ink-500">Late</p><p @class(['mt-2 text-2xl font-semibold tabular-nums', 'text-amber-700' => $attendance?->is_late])>{{ $attendance ? ($attendance->is_late ? $duration($attendance->late_minutes) : 'On time') : '—' }}</p><p class="mt-1 text-[11px] text-ink-500">Grace {{ $duration($rules['grace_minutes'] ?? null) }}</p></div>
    <div class="surface p-4"><p class="text-xs text-ink-500">Unpaid break</p><p class="mt-2 text-2xl font-semibold tabular-nums">{{ $duration($rules['break_minutes'] ?? null) }}</p><p class="mt-1 text-[11px] text-ink-500">Fixed deduction</p></div>
    <div class="surface p-4"><p class="text-xs text-ink-500">Present minimum</p><p class="mt-2 text-2xl font-semibold tabular-nums">{{ $duration($rules['present_minutes'] ?? null) }}</p><p class="mt-1 text-[11px] text-ink-500">Half Day {{ $duration($rules['half_day_minutes'] ?? null) }}</p></div>
</div>

<div class="surface grid min-w-0 gap-4 p-5">
    <div class="flex flex-wrap items-start justify-between gap-3"><h2 class="font-semibold">Shift times</h2><p class="text-xs text-ink-500">Raw punches are recorded by the server and cannot be edited.</p></div>
    <div class="overflow-x-auto"><table class="w-full min-w-[22rem] text-left text-sm"><caption class="sr-only">Scheduled, raw and effective shift times</caption>
        <thead><tr class="text-xs text-ink-500"><th scope="col" class="pb-2 pr-4 font-medium"><span class="sr-only">Event</span></th><th scope="col" class="pb-2 pr-4 font-medium">Scheduled</th><th scope="col" class="pb-2 pr-4 font-medium">Raw punch</th><th scope="col" class="pb-2 font-medium">Effective</th></tr></thead>
        <tbody>
        @foreach(['in' => ['Clock in', $schedule->scheduled_start_at, $attendance?->raw_clock_in_at, $attendance?->effective_clock_in_at], 'out' => ['Clock out', $schedule->scheduled_end_at, $attendance?->raw_clock_out_at, $attendance?->effective_clock_out_at]] as $event => [$label, $planned, $raw, $effective])
        @php($corrected = $attendance && ! $sameInstant($raw, $effective))
        <tr class="border-t border-ink-100"><th scope="row" class="py-3 pr-4 font-medium">{{ $label }}</th><td class="py-3 pr-4 tabular-nums">{{ $clock($planned) ?? '—' }}</td><td class="py-3 pr-4 tabular-nums">{{ $clock($raw) ?? 'Not recorded' }}</td><td class="py-3 tabular-nums"><span @class(['font-semibold' => $corrected])>{{ $clock($effective) ?? '—' }}</span>@if($corrected) <x-badge tone="brand" class="ml-1">Corrected</x-badge>@endif</td></tr>
        @endforeach
        </tbody></table></div>
    <p class="flex items-start gap-2 rounded-xl bg-ink-50 p-3 text-sm text-ink-600"><x-icon name="info" class="mt-0.5 size-4" /><span><strong class="font-semibold">Why {{ $labels[$status] ?? 'Needs review' }}?</strong> {{ $explanation }}</span></p>
    @if($attendance?->remarks)<p class="break-words text-sm">Shift remark: {{ $attendance->remarks }}</p>@endif
</div>

</section>
