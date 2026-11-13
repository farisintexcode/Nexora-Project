<section class="hr-page grid max-w-5xl gap-6">
    <x-feedback />
    @php($addingRider = ! $staffId && auth()->user()->addsRidersOnly())
    <x-page-header :title="$staffId ? 'Edit Staff' : ($addingRider ? 'Add New Rider' : 'Add New Staff')" eyebrow="Human Resources" :description="$addingRider ? 'Register a rider and securely provision their login account.' : 'Register staff and securely provision a linked login account.'" />
    <form wire:submit="save" class="grid gap-6">
        <fieldset class="form-section grid gap-5 sm:grid-cols-2">
            <legend>{{ $addingRider ? 'Rider Information' : 'Staff Information' }}</legend>
            <x-input name="data.staff_no" label="Staff ID" value="{{ $data['staff_no'] }}" readonly disabled :help="$staffId ? null : 'Auto-generated. Final number is confirmed on save.'" />
            <x-input name="data.email" label="Email Address" type="email" wire:model="data.email" maxlength="254" required />
            <x-input name="data.first_name" label="First Name" wire:model="data.first_name" maxlength="100" required />
            <x-input name="data.last_name" label="Last Name" wire:model="data.last_name" maxlength="100" required />
            @php($accountLocked = $staff?->user && ! auth()->user()->can('manageAccount', $staff))
            @php($invitableRoles = \App\Actions\Staff\InviteStaffAccount::invitableRoles(auth()->user()))
            @unless($addingRider)
            @php($assignableRoles = \App\Actions\Staff\SaveStaff::assignableRoles(auth()->user()))
            @php($currentRole = $staff ? \App\Actions\Staff\SaveStaff::currentRole($staff) : null)
            <x-select name="data.system_role" label="Role" wire:model="data.system_role" required :disabled="$accountLocked">
                <option value="" disabled>Role</option>
                @foreach(config('dcms.staff_roles') as $role)@if(in_array($role, $assignableRoles, true) || $role === $currentRole)<option value="{{ $role }}">{{ $role }}</option>@endif @endforeach
            </x-select>
            @endunless
            <x-select name="data.is_active" label="Staff Status" wire:model="data.is_active" required><option value="1">Active</option><option value="0">Inactive</option></x-select>
            <x-select name="data.gender_code" label="Gender (optional)" wire:model="data.gender_code"><option value="">Not specified</option>@foreach($genders as $gender)<option value="{{ $gender->code }}">{{ $gender->label }}</option>@endforeach</x-select>
            <x-select name="data.work_state_code" label="Work state (for public holidays)" wire:model="data.work_state_code"><option value="">Not set (national holidays only)</option>@foreach($states as $state)<option value="{{ $state->code }}">{{ $state->label }}</option>@endforeach</x-select>
            @if($staffId)
                <x-input name="data.phone" label="Work / mobile phone (optional)" wire:model="data.phone" maxlength="40" />
            @else
                <x-input name="data.phone" label="Work / mobile phone" wire:model="data.phone" maxlength="40" required />
            @endif
            <x-input name="data.home_phone" label="Home phone (optional)" wire:model="data.home_phone" maxlength="40" />
            <div class="sm:col-span-2"><x-textarea name="data.address" label="Address (optional)" wire:model="data.address" maxlength="2000" /></div>
            <p class="field-help sm:col-span-2">@if($addingRider)This person is added as a Rider. System Admin and Administrative staff are added by a System Admin.@else System Role controls the login permission bundle. Rider access also sets staff Rider eligibility. System Admin grants require roles.manage.@endif Work state decides which state public holidays apply; Kedah, Kelantan and Terengganu also follow a Friday–Saturday weekend.</p>
        </fieldset>
        <fieldset class="form-section grid gap-5">
            <legend>System Account Setup</legend>
            @if($staff?->user)
                <p class="field-help">Linked login: {{ $staff->user->email }}. Staff email changes do not change an existing login email.</p>
                <x-select name="data.account_status" label="Account Status" wire:model="data.account_status" :disabled="$accountLocked">
                    @if($staff->user->status->value === 'invited' || !$staff->user->email_verified_at)<option value="invited">Invited - password setup required</option>@endif
                    @if($staff->user->status->value === 'locked')<option value="locked">Locked</option>@endif
                    <option value="active">Active</option><option value="disabled">Disabled</option>
                </x-select>
                <p class="field-help">@if($accountLocked)Role and account status are managed by a System Admin.@else Invited accounts become active only after the user sets and confirms a personal password. Changing a role requires permission authority.@endif</p>
            @else
                @if($invitableRoles !== [])
                <label class="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" wire:model="data.create_account" class="size-4 accent-brand-primary">Create system login account</label>
                <p class="field-help">You send logins for <strong>{{ \Illuminate\Support\Arr::join($invitableRoles, ', ', ' and ') }}</strong>. {{ match (true) { ! in_array('Administrative', $invitableRoles, true) => 'Administrative and System Admin logins are sent by a System Admin.', ! in_array('Rider', $invitableRoles, true) => 'Rider logins are sent by Administrative staff or a System Admin.', default => '' } }} A single-use activation link lets the person choose their own password.</p>
                @else
                <p class="rounded-xl bg-brand-soft p-3 text-xs leading-5">This saves the staff record only. The login invitation is sent afterwards from the staff page.</p>
                @endif
            @endif
            @if(!config('dcms.terms_version') || !config('dcms.privacy_url'))<p role="status" class="field-help">Account setup requires the approved terms version and privacy URL to be configured.</p>@endif
            @if($staffId)<p class="field-help">{{ $outstanding ?? 0 }} outstanding current tasks. Resolve allocations before reducing availability.</p><x-textarea name="data.reason" label="Availability change reason" wire:model="data.reason" maxlength="1000" />@endif
        </fieldset>
        <fieldset class="form-section grid gap-5"><legend>Record Information</legend>
            <dl class="grid gap-4 text-sm sm:grid-cols-2"><div><dt>Created At</dt><dd>{{ $staff?->created_at?->timezone('Asia/Kuala_Lumpur')->format('d M Y H:i') ?? 'Generated automatically when saved' }}</dd></div>@if($staff)<div><dt>Updated At</dt><dd>{{ $staff->updated_at->timezone('Asia/Kuala_Lumpur')->format('d M Y H:i') }}</dd></div>@endif</dl><p class="field-help">Record timestamps are read-only and displayed in Asia/Kuala_Lumpur.</p>
        </fieldset>
        <div class="surface hr-actions sticky bottom-4 z-10 p-4"><x-button wire:loading.attr="disabled" wire:target="save">{{ $staffId ? 'Save staff' : ($addingRider ? 'Add rider' : 'Add staff') }}</x-button><a class="btn btn-secondary" href="{{ route('admin.staff.index') }}">Cancel</a><span wire:loading wire:target="save" class="text-xs text-ink-500">{{ $addingRider ? 'Saving rider...' : 'Saving staff...' }}</span></div>
    </form>
</section>
