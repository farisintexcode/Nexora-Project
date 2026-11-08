<form wire:submit="saveVisit" class="surface grid min-w-0 gap-5 p-5">
    <div><h2 class="text-lg font-semibold">Field Visit Details</h2><p class="mt-2 text-xs leading-5 text-ink-500">Save your visit before uploading proof. A blank visit time uses the server-recorded time in MYT.</p></div>
    @if(!$editable)<p role="status" class="rounded-xl bg-brand-soft p-3 text-sm">{{ in_array($task->work_status,['submitted','completed','cancelled']) ? 'This task is read-only. Your saved evidence and decisions remain in history.' : 'Start this task or its returned correction before editing.' }}</p>@endif
    <div class="grid min-w-0 gap-4 sm:grid-cols-2">
        <x-date-time name="visit-time" label="Visit Date / Time (MYT)" model="data.occurred_at" :disabled="!$editable" />
        <x-select name="data.outcome" label="Visit outcome" wire:model.live="data.outcome" required :disabled="!$editable"><option value="">Select outcome</option>@foreach($choices->get('activity_outcome',collect()) as $choice)<option value="{{ $choice->code }}">{{ $choice->label }}</option>@endforeach</x-select>
        <x-select name="data.dr_code" label="DR code (if applicable)" wire:model.live="data.dr_code" :disabled="!$editable"><option value="">Not recorded</option>@foreach($choices->get('dr_code',collect()) as $choice)<option value="{{ $choice->code }}">{{ $choice->code }} · {{ $choice->label }}</option>@endforeach</x-select>
    </div>
    <div class="grid gap-4 sm:grid-cols-3">
        @foreach(['availability'=>['Customer availability','availability'],'visit_status'=>['Site status','visit_status'],'contact_result'=>['Contact result','contact_result']] as $field=>[$label,$lookup])
        <x-select :name="'data.'.$field" :label="$label" wire:model="data.{{ $field }}" required :disabled="!$editable"><option value="">Select option</option>@foreach($choices->get($lookup,collect()) as $choice)<option value="{{ $choice->code }}">{{ $choice->label }}</option>@endforeach</x-select>
        @endforeach
    </div>
    <x-textarea name="data.visit_remarks" label="Remarks / Notes" wire:model="data.visit_remarks" rows="3" maxlength="5000" :required="in_array('visit',$visitPolicy['required_remarks']??[])" :disabled="!$editable" />
    @foreach(['collection'=>'Collection discussion remarks','customer'=>'Customer remarks'] as $field=>$label)
        @if(in_array($field,$visitPolicy['required_remarks']??[]))<x-textarea :name="'data.'.$field.'_remarks'" :label="$label" wire:model="data.{{ $field }}_remarks" rows="3" maxlength="5000" required :disabled="!$editable" />@endif
    @endforeach
    @if(($data['dr_code']??'')==='DR02' || filled($data['follow_up_due']??null))
    <div class="grid min-w-0 gap-4 rounded-xl border border-ink-200 p-4"><h3 class="text-sm font-semibold">Follow-up</h3><x-date-time name="follow-up-time" label="Follow-up due (MYT)" model="data.follow_up_due" :disabled="!$editable" /><x-input name="data.follow_up_remarks" label="Follow-up remarks" wire:model="data.follow_up_remarks" maxlength="5000" :disabled="!$editable" /></div>
    @endif
    @if(($data['dr_code']??'')==='DR01' || ($data['outcome']??'')==='payment_claimed' || filled($data['paid_amount']??null))
    <div class="grid gap-3 rounded-xl border border-ink-200 p-4"><p class="text-sm">Outstanding balance: <strong>{{ \App\Support\Money::format($task->latestBalance?->outstandingCents()) }}</strong></p><x-input name="data.paid_amount" label="Amount the customer paid (RM)" wire:model="data.paid_amount" inputmode="decimal" maxlength="20" :disabled="!$editable" help="A payment claim needs a Payment Evidence photo and independent Admin verification." /></div>
    @endif
    @if($visitPolicy['signboard_when_available']??false)<x-select name="data.signboard_available" label="Business signboard available?" wire:model="data.signboard_available" :disabled="!$editable"><option value="">Not confirmed</option><option value="1">Yes · add signboard proof</option><option value="0">No</option></x-select>@endif
    <details class="border-t border-ink-100 pt-4" @if($section==='business'||$errors->any()||blank($data['site_address']??null)||($visitPolicy['business_required']??false)||($data['business_applicable']??false)) open @endif>
        <summary class="min-h-11 cursor-pointer text-sm font-semibold">Additional Details</summary>
        <div class="mt-3 grid min-w-0 gap-4">
            <p class="text-xs text-ink-500">Use these fields when relevant. Required checks still apply for the selected visit purpose.</p>
            <x-select name="data.purpose" label="Visit purpose" wire:model.live="data.purpose" required :disabled="!$editable">@foreach($choices->get('visit_purpose',collect()) as $choice)<option value="{{ $choice->code }}">{{ $choice->label }}</option>@endforeach</x-select>
            <x-input name="data.site_address" label="Site address / location" wire:model="data.site_address" required maxlength="3000" :disabled="!$editable" help="Already filled from the task where available. Change only when the actual location differs." />
            <x-input name="data.ended_at" label="End time (optional)" type="datetime-local" wire:model="data.ended_at" :disabled="!$editable" class="min-w-0 max-w-full" />
            <x-input name="data.backdating_reason" label="Backdating Reason" wire:model="data.backdating_reason" maxlength="3000" :disabled="!$editable" help="Required for an earlier visit time. Existing backdating limits and server timestamps are preserved." />
            <x-input name="data.person_contacted" label="Person contacted" wire:model="data.person_contacted" maxlength="255" :disabled="!$editable" />
            @foreach(['collection'=>'Collection discussion remarks','customer'=>'Customer remarks'] as $field=>$label)
                @unless(in_array($field,$visitPolicy['required_remarks']??[]))<x-textarea :name="'data.'.$field.'_remarks'" :label="$label" wire:model="data.{{ $field }}_remarks" rows="3" maxlength="5000" :disabled="!$editable" />@endunless
            @endforeach
            @foreach(['usage'=>['Property usage','property_usage'],'property_type'=>['Type of property','property_type']] as $field=>[$label,$lookup])
                <x-select :name="'data.'.$field" :label="$label" wire:model="data.{{ $field }}" :disabled="!$editable"><option value="">Not recorded</option>@foreach($choices->get($lookup,collect()) as $choice)<option value="{{ $choice->code }}">{{ $choice->label }}</option>@endforeach</x-select>
            @endforeach
            <x-input name="data.usage_other" label="Other property usage explanation" wire:model="data.usage_other" maxlength="2000" :disabled="!$editable" />
            @unless($visitPolicy['signboard_when_available']??false)<x-select name="data.signboard_available" label="Business signboard available?" wire:model="data.signboard_available" :disabled="!$editable"><option value="">Not confirmed</option><option value="1">Yes</option><option value="0">No</option></x-select>@endunless
            <label class="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" wire:model.live="data.business_applicable" class="size-5 accent-brand-primary" @disabled(!$editable)>This visit concerns a business</label>
            <div id="business-editor" class="grid gap-4"><h3 class="text-sm font-semibold">Business details</h3>@foreach(['business_type'=>'Business type','business_nature'=>'Business nature','business_status'=>'Business status'] as $field=>$label)<x-select :name="'data.'.$field" :label="$label" wire:model="data.{{ $field }}" :disabled="!$editable"><option value="">Not recorded</option>@foreach($choices->get($field,collect()) as $choice)<option value="{{ $choice->code }}">{{ $choice->label }}</option>@endforeach</x-select>@endforeach<x-input name="data.shop_name" label="Business / shop name" wire:model="data.shop_name" maxlength="255" :disabled="!$editable" /><x-input name="data.business_other" label="Other explanation" wire:model="data.business_other" maxlength="3000" :disabled="!$editable" /></div>
            @unless(($data['dr_code']??'')==='DR01' || ($data['outcome']??'')==='payment_claimed' || filled($data['paid_amount']??null))<x-input name="data.paid_amount" label="Amount the customer paid (RM)" wire:model="data.paid_amount" inputmode="decimal" maxlength="20" :disabled="!$editable" help="Only when a payment is claimed. Payment Evidence is required." />@endunless
            @unless(($data['dr_code']??'')==='DR02' || filled($data['follow_up_due']??null))<x-date-time name="follow-up-time" label="Follow-up due (MYT)" model="data.follow_up_due" :disabled="!$editable" /><x-input name="data.follow_up_remarks" label="Follow-up remarks" wire:model="data.follow_up_remarks" maxlength="5000" :disabled="!$editable" />@endunless
        </div>
    </details>
    <x-button type="submit" :disabled="!$editable" wire:loading.attr="disabled" class="w-full">Save visit & remarks</x-button>
    <p wire:loading wire:target="saveVisit" role="status" class="text-sm">Saving your visit…</p>
</form>
