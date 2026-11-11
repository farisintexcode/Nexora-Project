<section class="grid min-w-0 gap-6"><x-feedback />
<x-page-header title="Call Centre" eyebrow="Customer conversations" description="Find the right customer, record every call outcome and keep promised follow-ups moving.">
    <x-slot:actions><a class="btn btn-primary" href="{{ route('admin.calls.follow-ups') }}"><x-icon name="clock" class="size-4" />Follow-up queue @if($stats['overdue'])<span class="rounded-full bg-red-600 px-2 text-[10px] leading-5 font-semibold text-white tabular-nums">{{ $stats['overdue'] }} overdue</span>@endif</a></x-slot:actions>
</x-page-header>

<section aria-label="Your call activity" class="grid grid-cols-2 gap-4 lg:grid-cols-4">
    @foreach([
        ['label' => 'My calls today', 'value' => $stats['callsToday'], 'icon' => 'phone', 'href' => null],
        ['label' => 'My calls · last 7 days', 'value' => $stats['callsWeek'], 'icon' => 'chart', 'href' => null],
        ['label' => 'My pending follow-ups', 'value' => $stats['myFollowUps'], 'icon' => 'calendar', 'href' => route('admin.calls.follow-ups', ['status' => 'pending', 'ownership' => 'mine'])],
        ['label' => 'Overdue follow-ups', 'value' => $stats['overdue'], 'icon' => 'clock', 'href' => route('admin.calls.follow-ups', ['status' => 'pending', 'ownership' => 'all', 'due' => 'overdue']), 'urgent' => true],
    ] as $stat)
        @php($isUrgent = ($stat['urgent'] ?? false) && $stat['value'] > 0)
        <{{ $stat['href'] ? 'a' : 'div' }} @if($stat['href']) href="{{ $stat['href'] }}" @endif @class(['metric-card grid gap-2 p-4!', 'border-red-200 bg-red-50' => $isUrgent])>
            <div class="flex items-center justify-between gap-2"><p class="text-xs font-medium text-ink-600">{{ $stat['label'] }}</p><x-icon :name="$stat['icon']" class="size-4 text-ink-500" /></div>
            <p @class(['text-3xl font-semibold tracking-tight tabular-nums', 'text-red-700' => $isUrgent])>{{ number_format($stat['value']) }}</p>
        </{{ $stat['href'] ? 'a' : 'div' }}>
    @endforeach
</section>

<div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
    <section class="surface grid min-w-0 content-start gap-4 p-5" aria-labelledby="customer-search-heading">
        <div><p class="eyebrow">Step 1</p><h2 id="customer-search-heading" class="mt-2 text-lg font-semibold">Find a customer</h2><p class="mt-1 text-xs text-ink-500">Search, then open the customer's call workspace to log the call.</p></div>
        <form wire:submit="$refresh" class="grid min-w-0 gap-4 md:grid-cols-[12rem_minmax(0,1fr)_auto] md:items-end">
            <x-select name="searchBy" label="Search by" wire:model="searchBy"><option value="reference">Customer reference</option><option value="name">Customer name</option><option value="contact">Phone or email</option><option value="account">Account number</option>@if($canTasks)<option value="task">Task reference (UUID)</option>@endif</x-select>
            <x-input name="query" label="Search customers" wire:model="query" maxlength="200" placeholder="Exact reference, number or name (3+ letters per word)" />
            <x-button variant="primary"><x-icon name="search" class="size-4" />Search</x-button>
            @if($canTasks)<div class="md:col-span-3"><x-select name="rider" label="Only customers of Rider (optional)" wire:model="rider"><option value="">All Riders</option>@foreach($riders as $option)<option value="{{ $option->public_id }}">{{ $option->full_name }} · {{ $option->staff_no }}</option>@endforeach</x-select></div>@endif
        </form>
        <div wire:loading.delay role="status" class="rounded-xl bg-brand-soft p-3 text-xs">Searching your permitted customer portfolio…</div>
        <div class="overflow-hidden rounded-xl border border-ink-200">
            <div class="flex items-center justify-between gap-2 border-b border-ink-100 bg-ink-50 px-4 py-2 text-xs text-ink-600"><span>{{ $searching ? 'Search results' : 'All customers' }}</span><span class="tabular-nums">{{ number_format($customers->total()) }} {{ $customers->total() === 1 ? 'customer' : 'customers' }}</span></div>
            <ul class="divide-y divide-ink-100">
                @forelse($customers as $customer)
                    <li wire:key="call-customer-{{ $customer->public_id }}"><a href="{{ route('admin.calls.show', $customer) }}" class="flex min-w-0 items-center gap-3 px-4 py-3 transition-colors hover:bg-brand-soft/60">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-soft text-xs font-semibold">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($customer->display_name_ciphertext, 0, 1)) }}</span>
                        <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold">{{ $customer->display_name_ciphertext }}</span><span class="block text-xs text-ink-500">{{ $customer->customer_reference }}</span></span>
                        <x-badge :tone="$customer->status==='active'?'success':'neutral'">{{ ucfirst($customer->status) }}</x-badge>
                        <span class="hidden items-center gap-1 text-xs font-semibold text-brand-primary sm:inline-flex">Open workspace<x-icon name="arrow-right" class="size-4" /></span>
                    </a></li>
                @empty
                    <li class="p-6"><x-empty-state title="No customers match" message="Check the search type — references, numbers and account numbers must match exactly." icon="phone" /></li>
                @endforelse
            </ul>
        </div>
        {{ $customers->links() }}
    </section>

    <aside class="grid min-w-0 content-start gap-4">
        <section class="surface grid gap-3 p-5" aria-labelledby="recent-calls-heading">
            <h2 id="recent-calls-heading" class="text-sm font-semibold">My recent calls</h2>
            @forelse($recentCalls as $call)
                <a wire:key="recent-call-{{ $call->public_id }}" href="{{ route('admin.calls.show', $call->customer) }}" class="grid gap-1 rounded-xl border border-ink-100 p-3 transition-colors hover:bg-brand-soft/60">
                    <span class="flex items-center justify-between gap-2"><span class="truncate text-xs font-semibold">{{ $call->customer->display_name_ciphertext }}</span><span class="shrink-0 text-[11px] text-ink-500">{{ $call->occurred_at->diffForHumans(short: true) }}</span></span>
                    <span class="text-[11px] text-ink-600">{{ $call->call_details_ciphertext['outcome_label'] ?? ucwords(str_replace('_', ' ', $call->outcome_code ?? 'Recorded')) }}</span>
                </a>
            @empty
                <p class="text-xs leading-5 text-ink-500">Calls you log will show here for quick return.</p>
            @endforelse
        </section>
        <section class="surface grid gap-3 p-5">
            <h2 class="text-sm font-semibold">How a call is recorded</h2>
            <ol class="grid gap-3 text-xs leading-5 text-ink-600">
                @foreach(['Find the customer and open their workspace.', 'Call them from your phone — tap a number to dial on mobile.', 'Choose the outcome, e.g. Promise to Pay or Call back.', 'Set a follow-up; it lands in your queue on the due date.'] as $step)
                    <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-brand-soft text-[11px] font-semibold">{{ $loop->iteration }}</span><span>{{ $step }}</span></li>
                @endforeach
            </ol>
        </section>
    </aside>
</div>

<section class="grid gap-4" aria-labelledby="call-capabilities-heading">
    <div><p class="eyebrow">What the Call Centre does</p><h2 id="call-capabilities-heading" class="mt-2 text-lg font-semibold">Capabilities</h2></div>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <x-capability-card icon="search" title="Customer lookup">Search by reference, name, phone, email, account or task — limited to customers you are allowed to see.</x-capability-card>
        <x-capability-card icon="phone" title="Call logging & outcomes">Record who you spoke to, the outcome and remarks. Calls up to a day late can be backdated with a reason.</x-capability-card>
        <x-capability-card icon="calendar" title="Promise to Pay & callbacks" :href="route('admin.calls.follow-ups')" action="Open follow-ups">Capture promised dates and amounts, and get a follow-up reminder on the due date.</x-capability-card>
        <x-capability-card icon="activity" title="Shared customer timeline">Every call and Rider site visit for a customer in one history, so the next agent has full context.</x-capability-card>
        <x-capability-card icon="message" title="WhatsApp chat & calling" status="soon">Message and call customers on WhatsApp from the workspace, with the conversation saved to the timeline.</x-capability-card>
        <x-capability-card icon="activity" title="Click-to-call & call recording" status="soon">Dial through a connected phone line, record calls and attach them to the outcome automatically.</x-capability-card>
    </div>
</section>
</section>
