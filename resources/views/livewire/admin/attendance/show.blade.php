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
    $showTime = fn ($value): string => $value ? \Carbon\CarbonImmutable::parse($value)->timezone($tz)->format('d M Y, H:i') : '—';
    $sameInstant = fn ($first, $second): bool => ($first === null && $second === null) || ($first !== null && $second !== null && \Carbon\CarbonImmutable::parse($first)->equalTo(\Carbon\CarbonImmutable::parse($second)));
    $statusTone = match ($status) { 'present' => 'success', 'absent' => 'danger', 'half_day', 'incomplete', 'needs_review' => 'warning', 'leave', 'open', 'expected' => 'brand', default => 'neutral' };
    $historyFields = ['day_type' => 'Day type', 'effective_clock_in_at' => 'Clock in', 'effective_clock_out_at' => 'Clock out', 'scheduled_start_at' => 'Scheduled start', 'scheduled_end_at' => 'Scheduled end'];
    $historyValue = fn (string $field, $value): string => $field === 'day_type' ? $dayLabel($value) : $showTime($value);
    $duration = fn (?int $minutes): string => \App\Livewire\Admin\Attendance\Show::duration($minutes);
@endphp
<section class="hr-page grid min-w-0 gap-6"><x-feedback />
<x-page-header :title="$schedule->rider->full_name" :eyebrow="'Attendance · '.$schedule->rider->staff_no" :description="$schedule->service_date->format('l, d M Y').' · Times in '.$tz"><x-slot:actions>@can('view', $schedule->rider)<a class="btn btn-secondary" href="{{ route('admin.staff.show', ['staff' => $schedule->rider->public_id]) }}"><x-icon name="user" />Staff profile</a>@endcan<a class="btn btn-secondary" href="{{ route('admin.attendance.index') }}"><x-icon name="arrow-left" />Back to attendance</a></x-slot:actions></x-page-header>

<div class="flex flex-wrap items-center gap-2"><x-badge :tone="$statusTone">{{ $labels[$status] ?? 'Needs review' }}</x-badge>@if($attendance?->is_late)<x-badge tone="warning">Late · {{ $duration($attendance->late_minutes) }}</x-badge>@endif @if($pendingCount)<x-badge tone="brand">Pending Correction</x-badge>@endif<span class="text-xs text-ink-500">{{ $dayLabel($schedule->day_type) }} · Policy {{ $schedule->policy->code }} v{{ $schedule->policy->version }}</span></div>
@if($leave)
<div role="status" @class(['flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 text-sm', 'border-brand-primary/40 bg-brand-soft' => $leave->status === 'approved', 'border-amber-200 bg-amber-50' => $leave->status === 'pending'])><span class="flex items-start gap-2"><x-icon name="calendar" class="mt-0.5 size-4" /><span><strong>{{ $leave->status === 'approved' ? 'On approved leave' : 'Leave requested — waiting for approval' }}</strong> · {{ $leave->type_snapshot['name'] ?? 'Leave' }}, {{ $leave->start_date->format('d M') }}{{ $leave->start_date->equalTo($leave->end_date) ? '' : ' – '.$leave->end_date->format('d M Y') }} ({{ $leave->total_days }} working {{ $leave->total_days === 1 ? 'day' : 'days' }})<span class="block text-xs text-ink-600">{{ $leaveCharged ? 'This day is counted as leave.' : 'This day is not counted as leave (rest day or public holiday).' }}</span></span></span><a class="btn btn-secondary btn-sm" href="{{ route('admin.leave.show', $leave->public_id) }}"><x-icon name="eye" />View leave request</a></div>
@endif

@if($reviewableCount)
<div role="status" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-primary/40 bg-brand-soft p-4 text-sm"><span class="flex items-center gap-2 font-medium"><x-icon name="info" class="size-4" />{{ $reviewableCount }} {{ $reviewableCount === 1 ? 'correction is' : 'corrections are' }} waiting for your review.</span><a class="btn btn-primary btn-sm" href="#correction-history">Review now<x-icon name="arrow-right" /></a></div>
@elseif($pendingCount)
<p role="status" class="rounded-xl bg-ink-50 p-4 text-sm text-ink-600">{{ $pendingCount }} {{ $pendingCount === 1 ? 'correction is' : 'corrections are' }} awaiting an independent authorized approver.</p>
@endif

<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div class="surface p-4"><p class="text-xs text-ink-500">Worked time</p><p class="mt-2 text-2xl font-semibold tabular-nums">{{ $attendance?->worked_minutes !== null ? $duration($attendance->worked_minutes) : 'Pending' }}</p><p class="mt-1 text-[11px] text-ink-500">After unpaid break</p></div>
    <div class="surface p-4"><p class="text-xs text-ink-500">Late</p><p @class(['mt-2 text-2xl font-semibold tabular-nums', 'text-amber-700' => $attendance?->is_late])>{{ $attendance ? ($attendance->is_late ? $duration($attendance->late_minutes) : 'On time') : '—' }}</p><p class="mt-1 text-[11px] text-ink-500">Grace {{ $duration($rules['grace_minutes'] ?? null) }}</p></div>
    <div class="surface p-4"><p class="text-xs text-ink-500">Unpaid break</p><p class="mt-2 text-2xl font-semibold tabular-nums">{{ $duration($rules['break_minutes'] ?? null) }}</p><p class="mt-1 text-[11px] text-ink-500">Fixed deduction</p></div>
    <div class="surface p-4"><p class="text-xs text-ink-500">Present minimum</p><p class="mt-2 text-2xl font-semibold tabular-nums">{{ $duration($rules['present_minutes'] ?? null) }}</p><p class="mt-1 text-[11px] text-ink-500">Half Day {{ $duration($rules['half_day_minutes'] ?? null) }}</p></div>
</div>

<div class="surface grid min-w-0 gap-4 p-5">
    <div class="flex flex-wrap items-start justify-between gap-3"><h2 class="font-semibold">Shift times</h2><p class="text-xs text-ink-500">Raw punches are read-only. Effective times include approved corrections.</p></div>
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

@if($canRequest)
<div x-data="{ open: {{ $errors->any() ? 'true' : 'false' }} }" class="grid min-w-0 gap-4">
<div x-show="!open" class="hr-actions"><button type="button" class="btn btn-secondary" @click="open = true"><x-icon name="edit" />Request a correction</button></div>
<form x-show="open" x-cloak wire:submit="requestCorrection" class="surface grid min-w-0 gap-5 p-5"><div class="flex items-start justify-between gap-3"><h2 class="text-lg font-semibold">Request a correction</h2><button type="button" class="btn btn-ghost" @click="open = false" aria-label="Close correction form"><x-icon name="close" /></button></div>
@if($attendance)<p class="text-sm text-ink-600">Change effective times only. An unlinked punch will be linked to this working schedule after approval. Leave clock out blank only when the interval should remain open.</p><div class="grid min-w-0 gap-4 sm:grid-cols-2"><x-date-time name="clock-in" label="Requested clock in (MYT) *" model="clockIn" /><x-date-time name="clock-out" label="Requested clock out (MYT)" model="clockOut" /></div>
@else<x-select name="day_type" label="Requested calendar type" wire:model="dayType"><option value="scheduled">Working day under pinned policy</option><option value="leave">Leave</option><option value="day_off">Rest Day</option><option value="public_holiday">Public Holiday</option><option value="cancelled">Deactivate this schedule</option></x-select><p class="text-xs text-ink-500">Calendar corrections preserve history and never create raw punches.</p>@endif
<x-input name="reason" label="Correction reason" wire:model="reason" required maxlength="5000" />
@can('configure',\App\Models\Attendance::class)<fieldset class="grid min-w-0 gap-4"><legend class="field-label mb-3">Planned shift correction (working days only)</legend><div class="grid min-w-0 gap-4 sm:grid-cols-2"><x-date-time name="scheduled-start" label="Requested scheduled start (MYT)" model="scheduledStart" /><x-date-time name="scheduled-end" label="Requested scheduled end (MYT)" model="scheduledEnd" default-time="18:00" /></div><p class="text-xs text-ink-500">Retain these values to keep the existing shift. Changes require independent approval and preserve the pinned policy and original schedule in history.</p></fieldset>@endcan
<div class="hr-actions"><x-confirm-action label="Submit correction request" title="Submit this correction?" message="These effective values will go for independent approval." /><button class="btn btn-secondary" type="button" wire:click="loadOriginal"><x-icon name="activity" />Reset to current values</button></div></form>
</div>
@endif

<div id="correction-history" class="grid scroll-mt-6 gap-4"><h2 class="text-lg font-semibold">Correction history</h2>
@forelse($requests as $request)
@php($changes = collect($historyFields)->filter(fn ($label, $field) => $historyValue($field, $request->before_values[$field] ?? null) !== $historyValue($field, $request->proposed_values[$field] ?? null)))
<article @class(['surface grid gap-4 p-5', 'border-brand-primary/50' => $request->status === 'pending']) wire:key="adjustment-{{ $request->public_id }}"><div class="flex flex-wrap items-center justify-between gap-3"><x-badge :tone="$request->status === 'approved' ? 'success' : ($request->status === 'pending' ? 'brand' : 'danger')">{{ ucfirst($request->status) }}</x-badge><p class="text-xs text-ink-500">Requested by {{ $request->requester->name }} · {{ $showTime($request->requested_at) }}</p></div><p class="break-words text-sm">{{ $request->reason }}</p>
@if($changes->isEmpty())<p class="text-xs text-ink-500">No value changes were requested.</p>@else
<div class="overflow-x-auto"><table class="w-full min-w-[20rem] text-left text-xs"><thead><tr class="text-ink-500"><th scope="col" class="pb-2 pr-4 font-medium">Field</th><th scope="col" class="pb-2 pr-4 font-medium">Before</th><th scope="col" class="pb-2 font-medium">Requested</th></tr></thead><tbody>@foreach($changes as $field => $label)<tr class="border-t border-ink-100"><th scope="row" class="py-2 pr-4 font-medium">{{ $label }}</th><td class="py-2 pr-4 text-ink-500 line-through decoration-ink-300">{{ $historyValue($field, $request->before_values[$field] ?? null) }}</td><td class="py-2 font-semibold">{{ $historyValue($field, $request->proposed_values[$field] ?? null) }}</td></tr>@endforeach</tbody></table></div>
@endif
@if($request->status!=='pending')<div class="border-t border-ink-100 pt-3"><p class="text-sm">{{ $request->review_remarks }}</p><p class="mt-1 text-xs text-ink-500">Reviewed by {{ $request->reviewer->name }} · {{ $showTime($request->reviewed_at) }}</p></div>
@elseif(auth()->user()->can('attendance.approve_correction') && auth()->id()!==$request->requested_by && auth()->user()->staff_id!==$schedule->rider_id)
<x-input :name="'reviewRemarks-'.$request->public_id" label="Reviewer remarks" wire:model="reviewRemarks" required maxlength="5000" />
<div class="hr-actions"><x-confirm-action label="Approve correction" title="Approve this correction?" message="This approves and applies these exact correction values." action="decide('{{ $request->public_id }}','approved')" /><x-confirm-action label="Reject correction" variant="secondary" title="Reject this correction?" message="The correction request will be rejected and its values will not be applied." action="decide('{{ $request->public_id }}','rejected')" confirm-label="Reject" /></div>
@else<p class="text-sm text-ink-500">Awaiting an independent authorized approver.</p>@endif</article>
@empty<x-empty-state title="No correction requests" message="Raw punches are read-only. Any change requires a reason and independent approval." icon="shield" />@endforelse
</div></section>
