@props(['rows','admin'=>false])
@php($time = fn($value) => $value ? \Carbon\CarbonImmutable::parse($value)->timezone(config('dcms.business_timezone'))->format('d M H:i') : '—')
<div class="grid min-w-0 gap-4">
@forelse($rows as $row)
<article class="surface grid min-w-0 gap-4 p-5" wire:key="attendance-{{ $row->schedule_public_id ?? $row->attendance_public_id }}">
<div class="flex flex-wrap items-start justify-between gap-3"><div><p @class(['eyebrow' => $admin, 'text-xs text-ink-500' => !$admin])>{{ $row->service_date }}</p><h2 class="mt-1 font-semibold">{{ $admin ? $row->rider_name : 'Your attendance' }}</h2>@if($admin)<p class="mt-1 text-xs text-ink-500">{{ $row->staff_no }}</p>@endif</div>
<div class="flex flex-wrap gap-2"><x-badge :tone="$row->result_status==='present'?'success':(in_array($row->result_status,['incomplete','absent','needs_review'])?'warning':'brand')">{{ \App\Services\Attendance\AttendanceStatus::LABELS[$row->result_status] ?? 'Needs review' }}</x-badge>@if($row->is_late)<x-badge tone="warning">Late · {{ $row->late_minutes }} min</x-badge>@endif @if($row->pending_correction)<x-badge>Pending Correction</x-badge>@endif @if($row->pending_leave ?? false)<x-badge tone="brand" title="A leave request covering this day is waiting for approval">Leave pending</x-badge>@endif</div></div>
<dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
<div><dt class="text-xs text-ink-500">Scheduled shift</dt><dd class="mt-1">{{ $time($row->scheduled_start_at) }} → {{ $time($row->scheduled_end_at) }}</dd></div>
<div><dt class="text-xs text-ink-500">Raw clock in / out</dt><dd class="mt-1">{{ $time($row->raw_clock_in_at) }} → {{ $time($row->raw_clock_out_at) }}</dd></div>
<div><dt class="text-xs text-ink-500">Effective in / out</dt><dd class="mt-1">{{ $time($row->effective_clock_in_at) }} → {{ $time($row->effective_clock_out_at) }}</dd></div>
<div><dt class="text-xs text-ink-500">Worked / unpaid break</dt><dd class="mt-1">{{ $row->worked_minutes === null ? 'Pending' : $row->worked_minutes.' min' }} / {{ $row->break_minutes === null ? 'Unscheduled' : $row->break_minutes.' min' }}</dd></div>
</dl>
<div class="flex flex-wrap items-center justify-between gap-3 border-t border-ink-200 pt-4"><p class="text-xs text-ink-500">{{ $row->policy_code ? $row->policy_code.' · version '.$row->policy_version : 'Unscheduled — ask an authorized administrator to assign a schedule and review the link.' }} · MYT</p>
@if($admin && $row->schedule_public_id)<a class="btn btn-secondary btn-sm" href="{{ route('admin.attendance.show',$row->schedule_public_id) }}">View attendance</a>
@elseif(!$admin && $row->schedule_public_id && $row->attendance_id && !$row->pending_correction)
@can('attendance.correct')<button type="button" class="btn btn-secondary btn-sm" wire:click="selectCorrection('{{ $row->schedule_public_id }}')">Request correction</button>@endcan
@endif</div>
</article>
@empty<x-empty-state title="No attendance in this view" message="Try another date range. Missing calendar setup is never counted as absence." icon="clock" />@endforelse
</div>
