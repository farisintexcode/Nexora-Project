<section class="grid min-w-0 gap-6">
<x-feedback />
<x-page-header title="Communications" eyebrow="Customer correspondence" description="Write approved wording once, then reach customers by email, private letter and — soon — WhatsApp.">
    <x-slot:actions>
        @can('communications.compose')<a class="btn btn-primary" href="{{ route('admin.communications.recipients') }}"><x-icon name="send" class="size-4" />Prepare email</a>@endcan
        @can('customers.view')<a class="btn btn-secondary" href="{{ route('admin.communications.letters', $context) }}"><x-icon name="file" class="size-4" />Private letters</a>@endcan
        @can('integrations.manage')<a class="btn btn-secondary" href="{{ route('admin.communications.receipts') }}"><x-icon name="layers" class="size-4" />SES receipt inbox</a>@endcan
    </x-slot:actions>
</x-page-header>

<section class="grid gap-4 lg:grid-cols-3" aria-label="Channels">
    <article class="surface grid content-start gap-4 p-5">
        <div class="flex items-start justify-between gap-3"><span class="icon-tile size-10 rounded-xl"><x-icon name="mail" class="size-5" /></span><x-badge :tone="$email['live'] ? 'success' : 'warning'">{{ $email['live'] ? 'Sending live' : 'Preview only' }}</x-badge></div>
        <div><h2 class="font-semibold">Email</h2><p class="mt-1 text-xs leading-5 text-ink-600">Pick customers, review each personalised email, then send in controlled batches with daily limits, retries and opt-out protection.</p></div>
        @if($activity)<p class="text-xs text-ink-500"><span class="text-xl font-semibold text-brand-dark tabular-nums">{{ number_format($activity['batches']) }}</span> email {{ $activity['batches'] === 1 ? 'batch' : 'batches' }} prepared</p>@endif
        @unless($email['live'])<p class="rounded-lg bg-amber-50 p-2 text-[11px] leading-4 text-amber-900">You can prepare and preview emails now. Sending starts once the email provider is connected.</p>@endunless
        @can('communications.compose')<a class="btn btn-secondary btn-sm justify-self-start" href="{{ route('admin.communications.recipients') }}">Prepare recipients<x-icon name="arrow-right" class="size-4" /></a>@endcan
    </article>
    <article class="surface grid content-start gap-4 p-5">
        @php($canGenerate = auth()->user()->can('communications.generate_letter'))
        <div class="flex items-start justify-between gap-3"><span class="icon-tile size-10 rounded-xl"><x-icon name="file" class="size-5" /></span><x-badge :tone="$canGenerate ? 'success' : 'warning'">{{ $canGenerate ? 'Available' : 'Needs permission' }}</x-badge></div>
        <div><h2 class="font-semibold">Private letters (PDF)</h2><p class="mt-1 text-xs leading-5 text-ink-600">Generate a letter for one customer from approved wording, check the exact preview, and download the PDF through a secure, expiring link.</p></div>
        @if($activity)<p class="text-xs text-ink-500"><span class="text-xl font-semibold text-brand-dark tabular-nums">{{ number_format($activity['letters']) }}</span> {{ $activity['letters'] === 1 ? 'letter' : 'letters' }} generated</p>@endif
        @unless($canGenerate)<p class="rounded-lg bg-amber-50 p-2 text-[11px] leading-4 text-amber-900">You can view letters. Generating new ones needs the letter permission from a System Admin.</p>@endunless
        @can('customers.view')<a class="btn btn-secondary btn-sm justify-self-start" href="{{ route('admin.communications.letters', $context) }}">Open letters<x-icon name="arrow-right" class="size-4" /></a>@endcan
    </article>
    <article class="surface grid content-start gap-4 border-dashed p-5">
        <div class="flex items-start justify-between gap-3"><span class="icon-tile size-10 rounded-xl text-ink-500"><x-icon name="message" class="size-5" /></span><x-badge>Coming soon</x-badge></div>
        <div><h2 class="font-semibold">WhatsApp</h2><p class="mt-1 text-xs leading-5 text-ink-600">Send approved WhatsApp templates, chat with customers and call them — every message saved to the customer's timeline.</p></div>
        <div class="grid gap-2 rounded-xl bg-ink-50 p-3" aria-label="Example WhatsApp conversation (preview only)">
            <p class="text-[10px] font-semibold tracking-wide text-ink-500 uppercase">Example · not a real message</p>
            <p class="max-w-[85%] justify-self-end rounded-xl rounded-tr-sm bg-green-50 px-3 py-2 text-[11px] leading-4 text-green-900">Hi Ahmad, this is a friendly reminder about your account ending 4821. Reply here if you'd like a call back.</p>
            <p class="max-w-[85%] rounded-xl rounded-tl-sm border border-ink-200 bg-white px-3 py-2 text-[11px] leading-4 text-ink-800">Can you call me after 5pm?</p>
        </div>
    </article>
</section>

<section class="surface grid gap-5 p-5 sm:p-6" aria-labelledby="flow-heading">
    <div class="flex flex-wrap items-end justify-between gap-3"><div><p class="eyebrow">How it works</p><h2 id="flow-heading" class="mt-2 text-lg font-semibold">From wording to delivery</h2></div><p class="text-xs text-ink-500">Every step is checked, and nothing is sent until you confirm.</p></div>
    <ol class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach([
            ['icon' => 'edit', 'title' => 'Write wording', 'text' => 'Draft the message with placeholders such as customer name and account.', 'ready' => true],
            ['icon' => 'shield', 'title' => 'Second-person approval', 'text' => 'Another admin approves the wording before it can be used.', 'ready' => true],
            ['icon' => 'users', 'title' => 'Choose recipients', 'text' => 'Pick contacts; opted-out or blocked contacts are excluded automatically.', 'ready' => true],
            ['icon' => 'eye', 'title' => 'Review every preview', 'text' => 'See the exact personalised message each customer would receive.', 'ready' => true],
            ['icon' => 'send', 'title' => 'Send & track', 'text' => 'Send in batches and follow delivered, bounced and complaint results.', 'ready' => $email['live']],
        ] as $step)
            <li class="grid content-start gap-2 rounded-xl border border-ink-200 p-4">
                <div class="flex items-center justify-between gap-2"><span class="grid size-8 place-items-center rounded-lg bg-brand-soft"><x-icon :name="$step['icon']" class="size-4" /></span><span class="text-[10px] font-semibold text-ink-500">STEP {{ $loop->iteration }}</span></div>
                <h3 class="text-sm font-semibold">{{ $step['title'] }}</h3>
                <p class="text-xs leading-5 text-ink-600">{{ $step['text'] }}</p>
                @unless($step['ready'])<x-badge tone="warning" class="justify-self-start">Waiting for email setup</x-badge>@endunless
            </li>
        @endforeach
    </ol>
</section>

<section class="grid gap-4" aria-labelledby="templates-heading">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div><p class="eyebrow">Approved wording</p><h2 id="templates-heading" class="mt-2 text-lg font-semibold">Message templates</h2><p class="mt-1 text-xs text-ink-500">Up to three email and letter options. Each one needs approved wording before it can be used.</p></div>
        <p class="text-sm"><span class="text-2xl font-semibold tabular-nums">{{ $approvedCount }}</span> <span class="text-ink-500">of 3 approved</span>@if($pendingApproval) · <span class="text-amber-800">{{ $pendingApproval }} awaiting approval</span>@endif</p>
    </div>
    <div class="grid gap-4 lg:grid-cols-3">
    @foreach($options as $option)
    <article class="surface grid content-start gap-4 p-5" wire:key="template-option-{{ $option['slot'] }}">
        <div class="flex items-center justify-between gap-3"><span class="grid size-10 place-items-center rounded-xl bg-brand-soft text-sm font-bold">{{ $option['slot'] }}</span><x-badge :tone="$option['approved']?'success':'neutral'">{{ $option['approved']?'Approved option':'Wording needed' }}</x-badge></div>
        <h3 class="font-semibold">{{ $option['approved']?->name ?? $option['latest']?->name ?? 'Email / letter option '.$option['slot'] }}</h3>
        @if($option['approved'])
            <div class="rounded-xl bg-ink-50 p-3"><p class="text-[10px] font-semibold tracking-wide text-ink-500 uppercase">Subject</p><p class="mt-1 wrap-break-word text-sm">{{ $option['approved']->subject_template }}</p></div>
            <p class="text-xs text-ink-500">Approved version {{ $option['approved']->version }}.0</p>
            <a class="btn btn-secondary btn-sm justify-self-start" href="{{ route('admin.communications.templates.edit',['template'=>$option['approved']->public_id,...$context]) }}">View approved option {{ $option['slot'] }}</a>
        @else
            <p class="text-sm leading-6 text-ink-500">Save and approve your organisation's wording before this option can be selected.</p>
        @endif
        @if($option['draft'])<a class="btn btn-primary btn-sm justify-self-start" href="{{ route('admin.communications.templates.edit',['template'=>$option['draft']->public_id,...$context]) }}">{{ $option['draft']->status==='pending_approval'?'Review pending option ':'Open draft option ' }}{{ $option['slot'] }}</a>@elseif(!$option['approved'] && $option['latest'])<a class="btn btn-secondary btn-sm justify-self-start" href="{{ route('admin.communications.templates.edit',['template'=>$option['latest']->public_id,...$context]) }}">View option history {{ $option['slot'] }}</a>@endif
    </article>
    @endforeach
    </div>
</section>

<section class="grid gap-4 lg:grid-cols-2">
    <article class="surface grid content-start gap-4 p-5 sm:p-6" aria-labelledby="email-readiness-heading">
        <div class="flex items-start justify-between gap-3"><div><p class="eyebrow">Email</p><h2 id="email-readiness-heading" class="mt-2 text-lg font-semibold">Sending readiness</h2></div><x-badge :tone="$email['live'] ? 'success' : 'warning'">{{ collect($email['checks'])->where('ok', true)->count() }} of {{ count($email['checks']) }} ready</x-badge></div>
        <ul class="grid gap-2">
            @foreach($email['checks'] as $check)
                <li class="flex items-center gap-3 text-sm"><span @class(['grid size-6 shrink-0 place-items-center rounded-full', 'bg-green-50 text-green-800' => $check['ok'], 'bg-ink-100 text-ink-500' => ! $check['ok']])><x-icon :name="$check['ok'] ? 'check' : 'close'" class="size-3.5" /></span><span @class(['text-ink-500' => ! $check['ok']])>{{ $check['label'] }}</span></li>
            @endforeach
        </ul>
        <p class="text-xs leading-5 text-ink-500">Provider settings are managed by your system administrator. Until everything is ready, previews work but no email leaves the system.</p>
    </article>
    <article class="surface grid content-start gap-4 p-5 sm:p-6" aria-labelledby="safeguards-heading">
        <div><p class="eyebrow">Built-in safeguards</p><h2 id="safeguards-heading" class="mt-2 text-lg font-semibold">Protecting customers and the company</h2></div>
        <ul class="grid gap-3 text-sm">
            @foreach([
                ['shield', 'Customers who opt out, bounce or complain are blocked automatically.'],
                ['users', 'Wording and email permission need a second person to approve.'],
                ['eye', 'You see the exact message for every recipient before confirming.'],
                ['lock', 'Letters and message contents are stored encrypted and downloaded through expiring links.'],
                ['activity', 'Every send attempt and delivery result is kept as history.'],
            ] as [$icon, $text])
                <li class="flex gap-3"><x-icon :name="$icon" class="mt-0.5 size-4 shrink-0 text-brand-primary" /><span class="leading-6 text-ink-600">{{ $text }}</span></li>
            @endforeach
        </ul>
    </article>
</section>
</section>
