<section class="rider-page"><x-feedback />
<x-page-header title="Notifications" eyebrow="" description="Task, attendance and leave updates, in one place." />
<div class="rider-notification-list">
@forelse($notifications as $notification)
<article class="surface rider-notification-card" wire:key="rider-notification-{{ $notification->id }}"><span class="rider-record-icon"><x-icon :name="$notification->type===\App\Notifications\AttendanceCorrected::class?'clock':'shield'" /></span><div class="min-w-0 flex-1"><div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-sm font-semibold">{{ $notification->type===\App\Notifications\LeaveUpdated::class?'Leave update':($notification->type===\App\Notifications\AttendanceCorrected::class?'Attendance update':'Task review') }}</h2><time class="text-[11px] text-ink-500">{{ $notification->created_at->timezone(config('dcms.business_timezone'))->format('d M · H:i') }} MYT</time></div><p class="mt-2 text-sm leading-6 text-ink-600">{{ $notification->data['message'] }}</p>
@if($notification->type===\App\Notifications\LeaveUpdated::class)
    @if(in_array($notification->data['leave_request']??'', $accessibleLeave,true))<a class="rider-text-link" href="{{ route('rider.leave.show',$notification->data['leave_request']) }}">View Leave Request<x-icon name="arrow-right" class="size-4" /></a>@endif
@elseif($notification->type===\App\Notifications\AttendanceCorrected::class)<a class="rider-text-link" href="{{ route('rider.attendance') }}">View attendance<x-icon name="arrow-right" class="size-4" /></a>@elseif(in_array($notification->data['task']??'', $accessibleTasks,true))<a class="rider-text-link" href="{{ route('rider.tasks.show',$notification->data['task']) }}">View Task<x-icon name="arrow-right" class="size-4" /></a>@else<p class="mt-2 text-xs text-ink-500">This task is no longer in your current allocation.</p>@endif
</div></article>
@empty<x-empty-state title="You're up to date" message="Task reviews and attendance correction updates will appear here when available." icon="bell" />@endforelse
</div>{{ $notifications->links() }}
</section>
