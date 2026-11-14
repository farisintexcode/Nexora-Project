<section class="rider-page rider-attendance-page nexora-attendance" wire:poll.30s>
@php
    $serverNow = now()->timezone(config('dcms.business_timezone'));
    $shift = $open?->schedule ?? $todaySchedule;
    $currentPunch = $open ?? $todayPunch;
    $clockedOut = !$open && $todayPunch?->effective_clock_out_at;
    $onLeave = $todayLeave && !$clockedOut;
    $shiftLabel = $onLeave ? 'On Leave' : ($open ? 'Checked In' : ($clockedOut ? 'Checked Out' : ($todayPunch ? 'Attendance recorded' : 'Not Checked In')));
    $separateHistory = auth()->user()->canUseRiderInterface() && !auth()->user()->can('admin.access');
@endphp
<x-feedback />
<header class="attendance-welcome"><div><p class="rider-kicker">Welcome, {{ auth()->user()->name }}</p><h1>Your attendance</h1><p class="text-sm text-ink-500">Rider workspace · Your time, recorded accurately</p></div><a href="{{ $separateHistory ? route('rider.attendance.history') : '#attendance-history' }}" class="attendance-history-shortcut" aria-label="History" title="Attendance history"><x-icon name="clock" /><span class="sr-only sm:not-sr-only">History</span><x-icon name="chevron" class="hidden size-4 sm:block" /></a></header>
<div class="attendance-date-card"><span class="attendance-icon"><x-icon name="calendar" /></span><div><p class="rider-kicker">Today · Server-recorded time</p><p class="font-semibold">{{ $serverNow->format('d M Y, l') }}</p><p class="mt-1 text-xs text-ink-500">{{ $serverNow->format('H:i:s') }} · {{ config('dcms.business_timezone') }}</p></div></div>
<section class="attendance-current surface" aria-labelledby="current-shift-title"><div class="attendance-current-grid"><div class="attendance-current-status">
<div class="attendance-current-heading"><span class="attendance-status-icon"><x-icon name="clock" /></span><p class="rider-kicker" id="current-shift-title">Current shift</p><h2>{{ $shiftLabel }}</h2><div class="flex flex-wrap justify-center gap-2"><x-badge :tone="$open && !$onLeave?'brand':'neutral'">{{ $onLeave ? 'Approved leave' : ($open ? 'Open shift' : ($todayPunch ? 'Recorded' : 'Not clocked in')) }}</x-badge>@if($clockedOut)<x-badge :tone="$todayPunch->status==='present'?'success':'neutral'">{{ \App\Services\Attendance\AttendanceStatus::LABELS[$todayPunch->status] ?? 'Needs review' }}</x-badge>@endif</div></div>
@if($onLeave)<p class="attendance-context">You are on approved leave today ({{ $todayLeave->type_snapshot['name'] ?? 'Leave' }}). Clock in and clock out are not available.</p>@if($open)<p role="status" class="rounded-xl bg-amber-50 p-3 text-sm text-amber-950">You still have an unfinished clock-in from {{ $open->service_date->format('d M Y') }}. Ask an administrator to correct it.</p>@endif
@elseif($open)<p class="attendance-context">Clocked in {{ $open->raw_clock_in_at->timezone(config('dcms.business_timezone'))->format('d M Y H:i:s') }} MYT.<br>Service date {{ $open->service_date->toDateString() }}.</p>@if($open->schedule && now()->greaterThan($open->schedule->scheduled_end_at))<p role="status" class="rounded-xl bg-amber-50 p-3 text-sm text-amber-950">Incomplete: the scheduled shift ended without a clock out. Record the actual clock out now.</p>@endif
@elseif(!$todaySchedule)<p class="attendance-context">No schedule is assigned today. You may record your actual time; it will be marked Needs review for an administrator to check.</p>
@elseif($todaySchedule->day_type!=='scheduled')<p class="attendance-context">{{ \App\Services\Attendance\AttendanceStatus::LABELS[$todaySchedule->day_type] ?? $todaySchedule->day_type }}</p>@endif
</div><div class="attendance-current-controls">
@can('attendance.clock')
@if(!$open && !$todayPunch && !$todayLeave)<div class="attendance-remark"><x-input name="remarks" label="Shift remark (optional)" wire:model="remarks" maxlength="2000" /></div>@endif
<div class="attendance-punch-actions">
@if($onLeave)<span class="attendance-inactive-action" aria-disabled="true"><x-icon name="calendar" />On leave today</span>
@elseif($open)<span class="attendance-inactive-action" aria-disabled="true"><x-icon name="check" />Clocked In</span><x-confirm-action class="w-full" button-class="min-h-14 w-full" icon="logout" label="Clock Out" title="Clock out now?" message="Your clock out is recorded using the current server time." action="clock('out')" confirm-label="Clock out" />
@elseif(!$todayPunch)<x-button type="button" class="min-h-14 w-full" wire:click="clock('in')" wire:loading.attr="disabled"><x-icon name="logout" class="rotate-180" />Clock In</x-button><span class="attendance-inactive-action" aria-disabled="true"><x-icon name="logout" />Clock Out</span>
@else<div class="attendance-saved-note"><x-icon name="check" /><span>Your attendance is recorded. Contact an administrator if anything is wrong.</span></div>@endif
</div>
@endcan
<div class="attendance-shift-times"><div><p>Shift starts</p><strong>{{ $shift?->scheduled_start_at?->timezone(config('dcms.business_timezone'))->format('h:i A') ?? 'Not scheduled' }}</strong>@if($shift?->scheduled_start_at)<span>{{ $shift->scheduled_start_at->timezone(config('dcms.business_timezone'))->format('d M Y') }}</span>@endif</div><div><p>Shift ends</p><strong>{{ $shift?->scheduled_end_at?->timezone(config('dcms.business_timezone'))->format('h:i A') ?? 'Not scheduled' }}</strong>@if($shift?->scheduled_end_at)<span>{{ $shift->scheduled_end_at->timezone(config('dcms.business_timezone'))->format('d M Y') }}</span>@endif</div></div>
</div></div>@if($currentPunch)<dl class="attendance-recorded-times"><div><dt>Server clock in</dt><dd>{{ $currentPunch->raw_clock_in_at?->timezone(config('dcms.business_timezone'))->format('d M Y H:i:s') ?? '—' }}</dd></div><div><dt>Server clock out</dt><dd>{{ $currentPunch->raw_clock_out_at?->timezone(config('dcms.business_timezone'))->format('d M Y H:i:s') ?? 'Not recorded' }}</dd></div></dl>@endif
@if($todaySchedule)<p class="attendance-policy-note">Policy {{ $todaySchedule->policy->code }} v{{ $todaySchedule->policy->version }} · {{ $todaySchedule->policy->rules['break_minutes'] }} min fixed unpaid break per completed interval. Lateness is recorded separately.</p>@endif
<p class="attendance-policy-note"><x-icon name="shield" class="size-4" />A successful confirmation appears only after your punch is saved. Times cannot be manually edited.</p>
</section>
@if(auth()->user()->canUseRiderInterface())
<nav aria-label="Attendance shortcuts" class="grid gap-4 sm:grid-cols-2"><a href="{{ $separateHistory ? route('rider.attendance.history') : '#attendance-history' }}" class="surface flex min-h-24 items-center gap-4 p-5 transition-colors hover:border-brand-accent"><span class="attendance-icon"><x-icon name="clock" /></span><span class="min-w-0 flex-1"><span class="block font-semibold">Attendance History</span><span class="mt-1 block text-xs leading-5 text-ink-500">View previous attendance records.</span></span><x-icon name="chevron" class="size-4" /></a><a href="{{ route('rider.leave.index') }}" class="surface flex min-h-24 items-center gap-4 p-5 transition-colors hover:border-brand-accent"><span class="attendance-icon"><x-icon name="calendar" /></span><span class="min-w-0 flex-1"><span class="block font-semibold">Leave Request</span><span class="mt-1 block text-xs leading-5 text-ink-500">Apply for leave and check your applications.</span></span><x-icon name="chevron" class="size-4" /></a></nav>
@endif
@unless($separateHistory)
    @include('livewire.rider.attendance-history-panel')
@endunless
</section>
