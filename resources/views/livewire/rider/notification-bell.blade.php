<a href="{{ route('rider.notifications') }}" wire:poll.30s.visible aria-label="Notifications{{ $unread ? ', '.$unread.' unread' : '' }}" title="Notifications" class="rider-header-control relative">
    <x-icon name="bell" />
    @if($unread)<span class="absolute -top-1.5 -right-1.5 grid min-w-5 place-items-center rounded-full bg-brand-accent px-1 text-[10px] leading-5 font-bold text-brand-dark tabular-nums ring-2 ring-white" aria-hidden="true">{{ $unread > 99 ? '99+' : $unread }}</span>@endif
</a>
