<section class="hr-page grid gap-6" wire:poll.20s>
<x-feedback />
<x-page-header title="Leave Management" eyebrow="" description="Review leave applications and manage the organization’s leave types."><x-slot:actions>@if($canConfigure)<a class="btn btn-primary" href="{{ route('admin.leave.types') }}">Leave Types &amp; Entitlements</a>@endif<a class="btn btn-secondary" href="{{ route('admin.attendance.index') }}">Attendance</a>@if($selected)<a class="btn btn-secondary" href="{{ route('admin.leave.index') }}"><x-icon name="arrow-left" class="size-4" />All leave requests</a>@endif</x-slot:actions></x-page-header>
@if($selected)
    @php($isOwnRequest = auth()->id() === $selected->requested_by || auth()->user()->staff_id === $selected->rider_id)
    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <x-leave-details :request="$selected" :admin="true" />
        <aside class="grid gap-4 lg:sticky lg:top-6">
            <section class="surface grid gap-4 p-5" aria-labelledby="review-heading">
                <h2 id="review-heading" class="text-lg font-semibold">Review application</h2>
                @if($conflicts->isNotEmpty())
                    <div role="status" class="grid gap-2 rounded-xl bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                        <p class="font-semibold">This leave can't be approved yet — the Rider has attendance on these dates.</p>
                        <ul class="grid gap-1.5">@foreach($conflicts as $conflict)<li wire:key="conflict-{{ $conflict->public_id }}">{{ \App\Actions\Attendance\ManageLeave::describeConflict($conflict) }}@if($conflict->schedule) <a class="font-semibold underline underline-offset-2" href="{{ route('admin.attendance.show', $conflict->schedule->public_id) }}">Open attendance</a>@endif</li>@endforeach</ul>
                        <p>Correct the clock times on that attendance record, or reject this leave and ask the Rider to apply again.</p>
                    </div>
                @endif
                @if($unfinished->isNotEmpty())
                    <div role="status" class="grid gap-2 rounded-xl bg-brand-soft p-3 text-xs leading-5 text-ink-700">
                        <p class="font-semibold">The Rider has an unfinished clock-in on these dates. You can still approve.</p>
                        <ul class="grid gap-1.5">@foreach($unfinished as $shift)<li wire:key="unfinished-{{ $shift->public_id }}">{{ \App\Actions\Attendance\ManageLeave::describeConflict($shift) }}@if($shift->schedule) <a class="font-semibold underline underline-offset-2" href="{{ route('admin.attendance.show', $shift->schedule->public_id) }}">Open attendance</a>@endif</li>@endforeach</ul>
                        <p>Once approved, the day shows as leave. The clock-in record is kept as-is.</p>
                    </div>
                @endif
                @if($selected->status==='pending' && $canReview && ! $isOwnRequest)
                    <div class="grid gap-2"><label for="reviewer-remarks" class="field-label">Remarks <span class="font-normal text-ink-500">(required to reject)</span></label><textarea id="reviewer-remarks" wire:model="remarks" class="field min-h-28" maxlength="5000" placeholder="Optional note for approval, or the reason for rejection"></textarea>@error('remarks')<p class="text-xs text-red-700">{{ $message }}</p>@enderror @error('decision')<p class="text-xs text-red-700">{{ $message }}</p>@enderror</div>
                    <div class="grid grid-cols-2 gap-3 [&_.btn]:w-full">
                        <x-confirm-action label="Approve" variant="success" action="decide('approved')" confirmLabel="Approve leave" title="Approve this leave?" message="The Rider will be notified. This decision is final." />
                        <x-confirm-action label="Reject" variant="danger" action="decide('rejected')" confirmLabel="Reject leave" title="Reject this leave?" message="Make sure you entered a rejection reason. The Rider will be notified. This decision is final." />
                    </div>
                @elseif($selected->status==='pending' && $isOwnRequest)
                    <p class="text-sm text-ink-600">This is your own leave request. Another administrator must review it.</p>
                @elseif($selected->status==='pending')
                    <p class="text-sm text-ink-600">Your account doesn't have leave approval permission, so you can view this request but not approve or reject it. Ask a System Admin to grant your account the <code class="text-xs font-semibold">leave.approve</code> permission.</p>
                @else
                    <p class="text-sm text-ink-600">This request is {{ $selected->status }}. Its decision is final and read-only.</p>
                @endif
            </section>
            @if($selectedBalance)<x-leave-balance :balance="$selectedBalance" :year="$selected->start_date->year" :name="$selected->rider->full_name.' · '.($selected->type_snapshot['name'] ?? 'Leave')" :tracked="$selected->type_snapshot['tracks_balance'] ?? false" :requested="0" />@endif
        </aside>
    </div>
@else
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">@foreach(['all'=>'Total Leave Requests','pending'=>'Pending Approval','approved'=>'Approved Requests','rejected'=>'Rejected Requests'] as $key=>$label)<div class="surface p-5"><p class="text-xs text-ink-500">{{ $label }}</p><p class="mt-2 text-2xl font-semibold">{{ $key==='all'?$counts->sum():($counts[$key]??0) }}</p></div>@endforeach</div>
    <x-filter-bar name="leave" submit="filterRequests" :count="(int)($search !== '') + (int)($status !== '') + (int)($filterType !== '') + (int)($rider !== '') + (int)($from !== '' || $until !== '')">
<x-select name="rider" label="Rider / Staff" wire:model="rider"><option value="">All accessible Riders</option>@foreach($riders as $option)<option value="{{ $option->public_id }}">{{ $option->staff_no }} - {{ $option->full_name }}</option>@endforeach</x-select>
<x-select name="status" label="Status" wire:model="status"><option value="">All statuses</option>@foreach(['pending','approved','rejected','cancelled'] as $value)<option value="{{ $value }}">{{ ucfirst($value) }}</option>@endforeach</x-select>
<x-date-range name="leave-date" label="Date" from="from" until="until" />
<x-slot:advanced><div class="grid gap-4 sm:grid-cols-2"><x-input name="search" label="Rider Name or Employee ID" wire:model="search" maxlength="100" /><x-select name="filterType" label="Leave Type" wire:model="filterType"><option value="">All leave types</option>@foreach($types as $leaveType)<option value="{{ $leaveType->public_id }}">{{ $leaveType->name }}</option>@endforeach</x-select></div></x-slot:advanced>
</x-filter-bar>
    <div class="surface overflow-hidden"><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-brand-soft text-xs"><tr>@foreach(['Rider','Employee ID','Leave Type','Dates','Total Days','Submitted','Status','Actions'] as $label)<th class="whitespace-nowrap p-4">{{ $label }}</th>@endforeach</tr></thead><tbody>@forelse($requests as $request)<tr class="border-t border-ink-100" wire:key="leave-{{ $request->public_id }}"><td class="p-4">{{ $request->rider->full_name }}</td><td class="p-4">{{ $request->rider->staff_no }}</td><td class="p-4">{{ $request->type_snapshot['name'] }}</td><td class="whitespace-nowrap p-4">{{ $request->start_date->format('d M Y') }}<br>{{ $request->end_date->format('d M Y') }}</td><td class="p-4">{{ $request->total_days }}</td><td class="whitespace-nowrap p-4">{{ $request->submitted_at->timezone(config('dcms.business_timezone'))->format('d M Y, H:i') }} MYT</td><td class="p-4"><x-leave-status :status="$request->status" /></td><td class="p-4"><a class="btn btn-secondary btn-sm whitespace-nowrap" href="{{ route('admin.leave.show',$request->public_id) }}">View Request</a></td></tr>@empty<tr><td colspan="8" class="p-8 text-center text-ink-500">No leave requests match these filters.</td></tr>@endforelse</tbody></table></div></div>{{ $requests->links() }}
    @if($updates->isNotEmpty())<section class="surface grid gap-3 p-5"><h2 class="font-semibold">Recent leave notifications</h2>@foreach($updates as $update)<a class="flex min-h-11 items-center justify-between gap-3 rounded-xl border border-ink-100 p-3 text-sm" href="{{ route('admin.leave.show',$update->data['leave_request']) }}">{{ $update->data['message'] }}<x-icon name="arrow-right" class="size-4" /></a>@endforeach</section>@endif
@endif
</section>
