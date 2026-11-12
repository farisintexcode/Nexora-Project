<section class="grid gap-7" wire:poll.30s>
    <x-feedback />
    <x-page-header class="dashboard-heading" :title="'Welcome, '.auth()->user()->name" eyebrow="System administration" description="User accounts, access and system settings. Assignments, tasks, attendance and leave are handled by Administrative staff.">
        <x-slot:actions>@can('create', \App\Models\Staff::class)<a class="btn btn-primary" href="{{ route('admin.staff.create') }}"><x-icon name="plus" class="size-4" />Add New Staff</a>@endcan</x-slot:actions>
    </x-page-header>

    @php($accountCards = [
        'active' => ['Active accounts', 'check', 'bg-green-600', 'Can log in'],
        'invited' => ['Waiting for activation', 'mail', 'bg-brand-primary', 'Invitation not completed yet'],
        'locked' => ['Locked', 'lock', 'bg-red-600', 'Cannot log in until unlocked'],
        'disabled' => ['Disabled', 'close', 'bg-ink-400', 'Turned off by an administrator'],
    ])
    <section aria-labelledby="accounts-heading" class="grid gap-4">
        <div><p class="eyebrow">Login accounts</p><h2 id="accounts-heading" class="mt-2 text-lg font-semibold tracking-tight">Account status</h2></div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach($accountCards as $state => [$label, $icon, $tile, $hint])
                <a href="{{ route('admin.staff.index', ['kind' => 'all', 'status' => '', 'accountStatus' => $state]) }}" @class(['metric-card flex items-center gap-4', 'border-red-200 bg-red-50' => $state === 'locked' && $statusCounts[$state] > 0])>
                    <span class="grid size-14 shrink-0 place-items-center rounded-xl text-white {{ $tile }}"><x-icon :name="$icon" class="size-6" /></span>
                    <span class="min-w-0"><span class="block text-xs font-medium text-ink-600">{{ $label }}</span><span @class(['mt-1 block text-2xl font-semibold tracking-tight tabular-nums', 'text-ink-400' => $statusCounts[$state] === 0])>{{ number_format($statusCounts[$state]) }}</span><span class="mt-0.5 block text-[11px] text-ink-500">{{ $hint }}</span></span>
                </a>
            @endforeach
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="surface grid content-start gap-4 p-6" aria-labelledby="roles-heading">
            <div><p class="eyebrow">Access</p><h2 id="roles-heading" class="mt-2 text-lg font-semibold tracking-tight">Accounts by role</h2></div>
            <dl class="grid gap-3">
                @foreach(['System Admin' => 'Controls user accounts, permissions and system settings.', 'Administrative' => 'Handles daily operations: customer data, task assignments and reports.', 'Rider' => 'Views assigned tasks, updates task status and uploads proof of work.'] as $role => $purpose)
                    <a href="{{ route('admin.staff.index', ['kind' => 'all', 'status' => '', 'role' => $role]) }}" class="flex items-center justify-between gap-4 rounded-xl border border-ink-100 p-4 transition-colors hover:bg-brand-soft/50">
                        <span class="min-w-0"><dt class="text-sm font-semibold">{{ $role }}</dt><span class="mt-1 block text-xs leading-5 text-ink-500">{{ $purpose }}</span></span>
                        <dd class="text-2xl font-semibold tabular-nums">{{ number_format($roleCounts[$role]) }}</dd>
                    </a>
                @endforeach
            </dl>
        </section>

        <section class="surface grid content-start gap-4 p-6" aria-labelledby="attention-heading">
            <div><p class="eyebrow">Today</p><h2 id="attention-heading" class="mt-2 text-lg font-semibold tracking-tight">Needs attention</h2></div>
            <ul class="grid gap-3">
                <li class="rounded-xl border border-ink-100 p-4">
                    <p class="flex items-center justify-between gap-3 text-sm font-semibold"><span class="flex items-center gap-2"><x-icon name="lock" class="size-4 text-red-700" />Locked accounts</span><x-badge :tone="$statusCounts['locked'] > 0 ? 'danger' : 'neutral'">{{ $statusCounts['locked'] }}</x-badge></p>
                    @if($lockedAccounts->isNotEmpty())
                        <ul class="mt-3 grid gap-1.5 text-xs">@foreach($lockedAccounts as $account)<li class="flex items-center justify-between gap-3"><span class="min-w-0 truncate">{{ $account->name }} · <span class="text-ink-500">{{ $account->email }}</span></span>@if($account->staff)<a class="shrink-0 font-semibold text-brand-primary underline underline-offset-4" href="{{ route('admin.staff.show', $account->staff->public_id) }}">Unlock</a>@endif</li>@endforeach</ul>
                    @else<p class="mt-1 text-xs text-ink-500">No one is locked out.</p>@endif
                </li>
                <li><a href="{{ route('admin.staff.index', ['kind' => 'office', 'status' => 'active', 'accountStatus' => 'none']) }}" class="flex items-center justify-between gap-3 rounded-xl border border-ink-100 p-4 text-sm transition-colors hover:bg-brand-soft/50"><span><span class="flex items-center gap-2 font-semibold"><x-icon name="user" class="size-4" />Administrative staff without a login</span><span class="mt-1 block text-xs text-ink-500">Open their staff page to send an invitation. Rider logins are sent by Administrative staff.</span></span><x-badge :tone="$staffWithoutLogin > 0 ? 'warning' : 'neutral'">{{ $staffWithoutLogin }}</x-badge></a></li>
                <li class="flex items-center justify-between gap-3 rounded-xl border border-ink-100 p-4 text-sm"><span><span class="flex items-center gap-2 font-semibold"><x-icon name="shield" class="size-4" />Failed logins (last 24 hours)</span><span class="mt-1 block text-xs text-ink-500">Many failures can mean a forgotten password or someone guessing.</span></span><x-badge :tone="$failedLogins >= 10 ? 'danger' : ($failedLogins > 0 ? 'warning' : 'neutral')">{{ $failedLogins }}</x-badge></li>
                @if($failedJobs !== null)
                    <li class="flex items-center justify-between gap-3 rounded-xl border border-ink-100 p-4 text-sm"><span><span class="flex items-center gap-2 font-semibold"><x-icon name="activity" class="size-4" />Failed background jobs</span><span class="mt-1 block text-xs text-ink-500">Imports, emails and exports that stopped with an error.@can('monitoring.view') Check System health for details.@endcan</span></span><x-badge :tone="$failedJobs > 0 ? 'danger' : 'neutral'">{{ $failedJobs }}</x-badge></li>
                @endif
            </ul>
        </section>
    </div>

    @php($settings = array_filter([
        ['Staff & accounts', 'Add staff, send invitations, change roles and fix login problems.', 'users', route('admin.staff.index', ['kind' => 'all']), \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\Staff::class)],
        ['Message templates', 'Approve the wording used for customer emails and letters.', 'mail', route('admin.communications.index'), \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\CommunicationTemplate::class)],
        ['System health', 'Server load, slow requests, queues and errors.', 'activity', url('/pulse'), auth()->user()->can('monitoring.view')],
    ], fn (array $card): bool => $card[4]))
    @if($settings !== [])
    <section aria-labelledby="settings-heading" class="grid gap-4">
        <div><p class="eyebrow">Configuration</p><h2 id="settings-heading" class="mt-2 text-lg font-semibold tracking-tight">System settings</h2></div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach($settings as [$title, $description, $icon, $href])
                <a href="{{ $href }}" class="metric-card grid content-start gap-3"><span class="icon-tile"><x-icon :name="$icon" /></span><span><span class="block text-sm font-semibold">{{ $title }}</span><span class="mt-1 block text-xs leading-5 text-ink-500">{{ $description }}</span></span><span class="inline-flex items-center gap-1 text-xs font-semibold text-brand-primary">Open<x-icon name="arrow-right" class="size-3.5" /></span></a>
            @endforeach
        </div>
    </section>
    @endif

    @can('audit.view')
    <section class="surface min-w-0 p-6" aria-labelledby="activity-heading">
        <div><p class="eyebrow">Audit trail</p><h2 id="activity-heading" class="mt-2 text-lg font-semibold tracking-tight">Recent account activity</h2><p class="mt-1 text-xs text-ink-500">Times shown in {{ config('dcms.business_timezone') }}. Passwords and links are never recorded.</p></div>
        <ul class="mt-5 grid gap-1">
            @forelse($activity as $event)
                <li class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 py-3 text-sm last:border-b-0"><span class="flex min-w-0 items-center gap-3"><span class="icon-tile size-9!"><x-icon name="activity" class="size-4" /></span><span class="min-w-0"><span class="block font-semibold">{{ \App\Livewire\Admin\SystemOverview::ACCOUNT_EVENTS[$event->action] ?? $event->action }}@if(filled($event->new_values_redacted['role'] ?? null)) <span class="font-normal text-ink-500">· {{ $event->new_values_redacted['role'] }}</span>@endif</span><span class="block text-xs text-ink-500">by {{ $event->actor?->name ?? 'System' }}@if($event->reason) · {{ $event->reason }}@endif</span></span></span>@if($event->entity_type === 'staff' && $event->entity_id)<a class="text-xs font-semibold text-brand-primary underline underline-offset-4" href="{{ route('admin.staff.show', $event->entity_id) }}">{{ $event->occurred_at->timezone(config('dcms.business_timezone'))->format('d M Y H:i') }}</a>@else<span class="text-xs text-ink-500">{{ $event->occurred_at->timezone(config('dcms.business_timezone'))->format('d M Y H:i') }}</span>@endif</li>
            @empty
                <li><x-empty-state icon="activity" title="No account activity yet" message="Invitations, role changes, unlocks and reset links will appear here." /></li>
            @endforelse
        </ul>
    </section>
    @endcan
</section>
