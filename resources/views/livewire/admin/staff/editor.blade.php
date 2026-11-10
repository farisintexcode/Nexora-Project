<section class="hr-page grid max-w-5xl gap-6">
    <x-feedback />
    <x-page-header :title="$staffId ? 'Edit Staff' : 'Add New Staff'" eyebrow="Human Resources" description="Register staff and securely provision a linked login account." />
    <form wire:submit="save" class="grid gap-6">
        <fieldset class="form-section grid gap-5 sm:grid-cols-2">
            <legend>Staff Information</legend>
            <x-input name="data.staff_no" label="Staff ID / Staff Number" wire:model="data.staff_no" maxlength="50" required />
            <x-input name="data.email" label="Email Address" type="email" wire:model="data.email" maxlength="254" required />
            <x-input name="data.first_name" label="First Name" wire:model="data.first_name" maxlength="100" required />
            <x-input name="data.last_name" label="Last Name" wire:model="data.last_name" maxlength="100" required />
            <x-select name="data.system_role" label="System Role" wire:model="data.system_role" required>
                <option value="" disabled>Select role</option>
                @foreach(config('dcms.staff_roles') as $role)<option value="{{ $role }}">{{ $role }}</option>@endforeach
            </x-select>
            <x-select name="data.is_active" label="Staff Status" wire:model="data.is_active" required><option value="1">Active</option><option value="0">Inactive</option></x-select>
            <x-select name="data.gender_code" label="Gender (optional)" wire:model="data.gender_code"><option value="">Not specified</option>@foreach($genders as $gender)<option value="{{ $gender->code }}">{{ $gender->label }}</option>@endforeach</x-select>
            <x-select name="data.work_state_code" label="Work state (for public holidays)" wire:model="data.work_state_code"><option value="">Not set (national holidays only)</option>@foreach($states as $state)<option value="{{ $state->code }}">{{ $state->label }}</option>@endforeach</x-select>
            <x-input name="data.phone" label="Work / mobile phone (optional)" wire:model="data.phone" maxlength="40" />
            <x-input name="data.home_phone" label="Home phone (optional)" wire:model="data.home_phone" maxlength="40" />
            <div class="sm:col-span-2"><x-textarea name="data.address" label="Address (optional)" wire:model="data.address" maxlength="2000" /></div>
            <p class="field-help sm:col-span-2">System Role controls the login permission bundle. Rider access also sets staff Rider eligibility. System Admin grants require roles.manage. Work state decides which state public holidays apply; Kedah, Kelantan and Terengganu also follow a Friday–Saturday weekend.</p>
        </fieldset>
        <fieldset class="form-section grid gap-5">
            <legend>System Account Setup</legend>
            @if($staff?->user)
                <p class="field-help">Linked login: {{ $staff->user->email }}. Staff email changes do not change an existing login email.</p>
                <x-select name="data.account_status" label="Account Status" wire:model="data.account_status">
                    @if($staff->user->status->value === 'invited' || !$staff->user->email_verified_at)<option value="invited">Invited - password setup required</option>@endif
                    @if($staff->user->status->value === 'locked')<option value="locked">Locked</option>@endif
                    <option value="active">Active</option><option value="disabled">Disabled</option>
                </x-select>
                <p class="field-help">Invited accounts become active only after the user sets and confirms a personal password. Changing a role requires permission authority.</p>
            @else
                <label class="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" wire:model="data.create_account" class="size-4 accent-brand-primary">Create system login account</label>
                <p class="field-help">Requires users.invite. A single-use activation link lets the user choose a personal password. Inactive staff cannot activate; activate staff and reissue the invitation first.</p>
            @endif
            @if(!config('dcms.terms_version') || !config('dcms.privacy_url'))<p role="status" class="field-help">Account setup requires the approved terms version and privacy URL to be configured.</p>@endif
            @if($staffId)<p class="field-help">{{ $outstanding ?? 0 }} outstanding current tasks. Resolve allocations before reducing availability.</p><x-textarea name="data.reason" label="Availability change reason" wire:model="data.reason" maxlength="1000" />@endif
        </fieldset>
        <fieldset class="form-section grid gap-5"><legend>Record Information</legend>
            <dl class="grid gap-4 text-sm sm:grid-cols-2"><div><dt>Created At</dt><dd>{{ $staff?->created_at?->timezone('Asia/Kuala_Lumpur')->format('d M Y H:i') ?? 'Generated automatically when saved' }}</dd></div>@if($staff)<div><dt>Updated At</dt><dd>{{ $staff->updated_at->timezone('Asia/Kuala_Lumpur')->format('d M Y H:i') }}</dd></div>@endif</dl><p class="field-help">Record timestamps are read-only and displayed in Asia/Kuala_Lumpur.</p>
        </fieldset>
        <div class="surface hr-actions sticky bottom-4 z-10 p-4"><x-button wire:loading.attr="disabled" wire:target="save"><x-icon name="check" />Save staff</x-button><a class="btn btn-secondary" href="{{ route('admin.staff.index') }}"><x-icon name="close" />Cancel</a><span wire:loading wire:target="save" class="text-xs text-ink-500">Saving staff...</span></div>
    </form>
</section>
