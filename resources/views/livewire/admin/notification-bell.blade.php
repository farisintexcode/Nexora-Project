<div class="relative" wire:poll.60s.visible="refreshCounts" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
    <button type="button" class="theme-toggle relative grid size-11 shrink-0 place-items-center rounded-xl border border-ink-200 bg-white text-ink-600 transition-colors hover:bg-brand-soft" @click="open = !open" :aria-expanded="open" aria-haspopup="true" aria-controls="admin-notifications" aria-label="Notifications{{ $unread ? ', '.$unread.' unread' : '' }}" title="Notifications">
        <x-icon name="bell" />
        @if($unread)<span class="absolute -top-1 -right-1 grid min-w-5 place-items-center rounded-full bg-red-600 px-1 text-[10px] leading-5 font-semibold text-white tabular-nums ring-2 ring-white" aria-hidden="true">{{ $unread > 99 ? '99+' : $unread }}</span>@endif
    </button>
    <div id="admin-notifications" x-cloak x-show="open" x-transition.origin.top.right class="surface absolute right-0 z-40 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden shadow-lg" role="region" aria-label="Notifications">
        <div class="flex items-center justify-between gap-3 border-b border-ink-100 px-4 py-3">
            <p class="text-sm font-semibold">Notifications</p>
            @if($unread)<button type="button" wire:click="markAllRead" class="text-xs font-semibold text-brand-primary underline underline-offset-4">Mark all as read</button>@endif
        </div>
        <ul class="max-h-96 overflow-y-auto">
            @forelse($notifications as $notification)
                @php($isUnread = $notification->read_at === null)
                @php($icon = match ($notification->type) { \App\Notifications\TaskSubmitted::class => 'shield', \App\Notifications\LeaveUpdated::class => 'calendar', default => 'clock' })
                <li wire:key="admin-notification-{{ $notification->id }}" class="border-b border-ink-100 last:border-b-0">
                    <button type="button" wire:click="open('{{ $notification->id }}')" @class(['flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-brand-soft', 'bg-brand-soft/50' => $isUnread])>
                        <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-ink-50 text-ink-600"><x-icon :name="$icon" class="size-4" /></span>
                        <span class="min-w-0 flex-1">
                            <span @class(['block text-xs leading-5', 'font-semibold text-brand-dark' => $isUnread, 'text-ink-600' => ! $isUnread])>{{ $notification->data['message'] ?? 'Update' }}</span>
                            <span class="mt-0.5 block text-[11px] text-ink-500">{{ $notification->created_at->diffForHumans() }}</span>
                        </span>
                        @if($isUnread)<span class="mt-2 size-2 shrink-0 rounded-full bg-brand-accent" aria-label="Unread"></span>@endif
                    </button>
                </li>
            @empty
                <li class="px-4 py-8 text-center text-xs text-ink-500">You're all caught up.</li>
            @endforelse
        </ul>
    </div>
</div>
