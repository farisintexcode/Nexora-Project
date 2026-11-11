@props(['request', 'admin' => false])
<section class="surface grid gap-5 p-5 sm:p-6">
    <header class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-semibold">{{ $request->type_snapshot['name'] }}</h2><p class="mt-1 break-all text-xs text-ink-500">Request {{ $request->public_id }}</p></div><x-leave-status :status="$request->status" /></header>
    @if($admin)<div class="rounded-xl bg-brand-soft p-4"><p class="text-sm font-semibold">{{ $request->rider->full_name }}</p><p class="mt-1 text-xs text-ink-500">Employee {{ $request->rider->staff_no }}</p></div>@endif
    <dl class="grid gap-5 text-sm sm:grid-cols-2"><div><dt class="text-xs text-ink-500">Start Date</dt><dd class="mt-2 font-medium">{{ $request->start_date->format('d M Y') }}</dd></div><div><dt class="text-xs text-ink-500">End Date</dt><dd class="mt-2 font-medium">{{ $request->end_date->format('d M Y') }}</dd></div><div><dt class="text-xs text-ink-500">Total Leave Days</dt><dd class="mt-2 font-medium">{{ $request->total_days }} working {{ $request->total_days===1?'day':'days' }}</dd></div><div><dt class="text-xs text-ink-500">Application Date</dt><dd class="mt-2">{{ $request->submitted_at->timezone(config('dcms.business_timezone'))->format('d M Y, H:i') }} MYT</dd></div><div class="sm:col-span-2"><dt class="text-xs text-ink-500">Reason for Leave</dt><dd class="mt-2 whitespace-pre-line break-words leading-6">{{ $request->reason }}</dd></div><div class="sm:col-span-2"><dt class="text-xs text-ink-500">Supporting Document</dt><dd class="mt-2">
        @if($request->document)
            @php($previewUrl = route('leave.document', ['leave' => $request->public_id, 'preview' => 1]))
            @php($isPdf = $request->document->mime_type === 'application/pdf')
            <div class="overflow-hidden rounded-xl border border-ink-200">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 bg-ink-50 px-3 py-2">
                    <span class="flex items-center gap-2 text-xs font-semibold"><x-icon name="file" class="size-4" />{{ $isPdf ? 'PDF document' : 'Image' }} · {{ number_format($request->document->size_bytes / 1024, 0) }} KB</span>
                    <span class="flex flex-wrap gap-2"><a class="btn btn-secondary btn-sm" href="{{ $previewUrl }}" target="_blank" rel="noopener"><x-icon name="external" class="size-4" />Open</a><a class="btn btn-secondary btn-sm" href="{{ route('leave.document', $request->public_id) }}"><x-icon name="download" class="size-4" />Download</a></span>
                </div>
                <div wire:ignore class="bg-ink-50/50">
                    @if($isPdf)
                        <iframe src="{{ $previewUrl }}" title="Supporting document preview" class="block h-128 w-full bg-white" loading="lazy"></iframe>
                    @else
                        <a href="{{ $previewUrl }}" target="_blank" rel="noopener" class="block p-3"><img src="{{ $previewUrl }}" alt="Supporting document" class="mx-auto max-h-128 w-auto rounded-lg object-contain" loading="lazy"></a>
                    @endif
                </div>
            </div>
        @else<span class="text-ink-500">No document attached</span>@endif
    </dd></div>
    @if($request->reviewed_at)<div><dt class="text-xs text-ink-500">Reviewed By</dt><dd class="mt-2">{{ $request->reviewer?->name }}</dd></div><div><dt class="text-xs text-ink-500">Decision Date</dt><dd class="mt-2">{{ $request->reviewed_at->timezone(config('dcms.business_timezone'))->format('d M Y, H:i') }} MYT</dd></div><div class="sm:col-span-2"><dt class="text-xs text-ink-500">Admin Remarks / Rejection Reason</dt><dd class="mt-2 whitespace-pre-line break-words leading-6">{{ $request->reviewer_remarks ?: 'No additional remarks' }}</dd></div>@endif
    @if($request->cancelled_at)<div><dt class="text-xs text-ink-500">Cancelled On</dt><dd class="mt-2">{{ $request->cancelled_at->timezone(config('dcms.business_timezone'))->format('d M Y, H:i') }} MYT</dd></div>@endif
    </dl>
    @php($scheduleDays = $request->relationLoaded('schedules') ? $request->schedules->keyBy('id') : collect())
    <details class="border-t border-ink-100 pt-4" @if($admin) open @endif><summary class="cursor-pointer text-xs font-semibold">Leave days breakdown</summary><p class="mt-2 text-xs text-ink-500">Each date in this application and whether it uses up leave. Only scheduled working days are counted — rest days and public holidays are not.{{ $admin ? ' Use “View attendance” to check the Rider’s attendance record for that date.' : '' }}</p><ul class="mt-3 grid gap-1.5 text-xs">@foreach($request->calendar_snapshot as $day)@php($scheduleDay = $scheduleDays->get($day['id']))@php($holidayName = $day['day_type'] === 'public_holiday' && $scheduleDay ? \Illuminate\Support\Str::after((string) $scheduleDay->remarks, ' · ') : null)<li class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-ink-50 px-3 py-2"><span><span class="font-semibold">{{ \Carbon\CarbonImmutable::parse($day['service_date'])->format('D, d M Y') }}</span> · @if($day['day_type'] === 'scheduled')<span class="text-ink-800">Working day — counted as leave</span>@else<span class="text-ink-500">{{ \App\Services\Attendance\AttendanceStatus::LABELS[$day['day_type']] ?? ucwords(str_replace('_', ' ', $day['day_type'])) }}{{ $holidayName && $holidayName !== $scheduleDay->remarks ? ': '.$holidayName : '' }} — not counted</span>@endif</span>@if($admin && $scheduleDay)<a class="text-xs font-semibold text-brand-primary underline underline-offset-4" href="{{ route('admin.attendance.show', $scheduleDay->public_id) }}">View attendance</a>@endif</li>@endforeach</ul></details>
</section>
