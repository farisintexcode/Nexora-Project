<section class="grid min-w-0 gap-6" wire:poll.15s>
<x-feedback />
<x-page-header title="Private letters" eyebrow="Customer correspondence" description="Review approved wording and source data, then generate a private PDF for authorized use."><x-slot:actions><a class="btn btn-secondary" href="{{ route('admin.communications.index') }}">Communications</a></x-slot:actions></x-page-header>
<div class="grid min-w-0 gap-6 xl:grid-cols-3">
<aside class="surface grid min-w-0 content-start gap-5 p-5 sm:p-6">
<h2 class="text-lg font-semibold">Prepare a letter</h2>
@if($canGenerate)
<form wire:submit="selectCustomer" class="grid gap-4"><x-input name="customerReference" label="Exact customer reference" wire:model="customerReference" maxlength="100" required /><x-button variant="secondary" wire:loading.attr="disabled">Find customer</x-button></form>
@if($customer)<div class="rounded-xl bg-brand-soft p-4"><p class="font-semibold break-words">{{ $customer->display_name_ciphertext }}</p><p class="mt-2 text-xs">{{ $customer->customer_reference }}</p></div>@endif
<form wire:submit="preview" class="grid gap-4">
<x-select name="templateId" label="Approved template" wire:model.live="templateId" required><option value="">Choose an approved option</option>@foreach($templates as $template)<option value="{{ $template->public_id }}">{{ $template->name }} · v{{ $template->version }}.0</option>@endforeach</x-select>
@if($customer)
<x-select name="accountId" label="Account context (optional)" wire:model.live="accountId"><option value="">No account selected</option>@foreach($accounts as $account)<option value="{{ $account->public_id }}">{{ $account->account_number }}</option>@endforeach</x-select>
@if($tasks->isNotEmpty())<x-select name="taskId" label="Task context (optional)" wire:model.live="taskId"><option value="">No task selected</option>@foreach($tasks as $task)<option value="{{ $task->public_id }}">{{ $task->account->account_number }} · {{ $task->public_id }}</option>@endforeach</x-select>@endif
<x-button wire:loading.attr="disabled">Review letter preview</x-button>
@else<p class="text-sm leading-6 text-ink-500">Find a customer to prepare their personalized letter.</p>@endif
</form>
@else<p class="text-sm leading-7 text-ink-600">You can review accessible letter history. Preparing a letter requires the separate letter generation permission.</p>@endif
<p class="text-xs leading-6 text-ink-500">Required balances and payment due dates must come from approved source data. Sample values cannot be used for customer letters.</p>
<p wire:loading.delay wire:target="preview,selectCustomer,confirm,viewLetter" role="status" class="text-sm text-ink-500">Checking access and saved content…</p>
</aside>
<div class="min-w-0 xl:col-span-2">
@if($preview)
<article class="surface grid min-w-0 gap-5 p-5 sm:p-7" aria-label="Letter preview">
<div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-xl font-semibold">Saved letter preview</h2><x-badge :tone="$preview->delivery_status==='generated'?'success':'neutral'">{{ ucfirst($preview->delivery_status) }}</x-badge></div>
<p class="break-all text-xs text-ink-500">{{ $preview->public_id }} · Template v{{ $preview->context_snapshot_ciphertext['template_version'] }}.0</p>
<h3 class="break-words text-lg font-semibold">{{ $preview->subject_ciphertext }}</h3><p class="whitespace-pre-wrap break-words text-sm leading-7">{{ $preview->body_ciphertext }}</p>
<dl class="grid gap-4 border-t border-ink-200 pt-5 sm:grid-cols-2">@foreach($preview->context_snapshot_ciphertext['sources'] as $key=>$source)<div><dt class="field-label">{{ ucwords(str_replace('_',' ',$key)) }}</dt><dd class="mt-1 text-xs leading-6 text-ink-500">{{ $source }}</dd></div>@endforeach</dl>
@if($preview->failure_code)<p role="alert" class="rounded-xl border border-amber-300 p-4 text-sm">{{ $preview->failure_code==='generation_unavailable'?'PDF generation failed. Review the worker and private storage service, then retry.':'Generation stopped because access, template approval or source data changed. Create a new preview after review.' }}</p>@endif
@if($canGenerate && $preview->requested_by===auth()->id() && in_array($preview->delivery_status,['draft','failed'],true))
<form wire:submit="confirm"><x-confirm-action :label="$preview->delivery_status==='failed' ? 'Retry PDF generation' : 'Confirm and generate PDF'" title="Generate private PDF?" message="This creates a private PDF from this exact saved letter and records an artifact for authorized use." /></form>
@endif
@if($preview->delivery_status==='queued')<p role="status" class="rounded-xl bg-brand-soft p-4 text-sm">PDF generation is queued. This page refreshes its status automatically.</p>@endif
@if($download)<a class="btn btn-primary justify-self-start" href="{{ $download }}">Download private PDF</a><p class="text-xs text-ink-500">The download link expires after five minutes. Access is checked again when you download.</p>@endif
<p class="text-xs leading-6 text-ink-500">Generated records a private file. Email delivery and postal dispatch are separate operations.</p>
</article>
@else<x-empty-state title="Review before generating" message="Select a customer and approved template to review the exact letter and its source data. Existing letters are available in the history below." />@endif
</div></div>
<section class="grid min-w-0 gap-4"><h2 class="text-xl font-semibold">Letter history</h2>
<div class="grid gap-4 md:grid-cols-2">@forelse($history as $letter)<article class="surface grid min-w-0 gap-3 p-5" wire:key="letter-{{ $letter->public_id }}"><div class="flex flex-wrap justify-between gap-3"><h3 class="font-semibold break-words">{{ $letter->customer->customer_reference }}</h3><x-badge :tone="$letter->delivery_status==='generated'?'success':'neutral'">{{ ucfirst($letter->delivery_status) }}</x-badge></div><p class="text-sm">{{ $letter->template->name }} · v{{ $letter->context_snapshot_ciphertext['template_version'] }}.0</p><p class="break-all text-xs text-ink-500">{{ $letter->created_at->timezone(config('dcms.business_timezone'))->format('d M Y H:i') }} MYT · {{ $letter->public_id }}</p><x-button variant="secondary" wire:click="viewLetter('{{ $letter->public_id }}')" wire:loading.attr="disabled">Review saved letter</x-button></article>
@empty<div class="md:col-span-2"><x-empty-state title="No letters in this view" message="Letters appear here after an authorized user saves a personalized preview." /></div>@endforelse</div>{{ $history->links() }}
</section></section>

