<section class="hr-page grid gap-6" wire:poll.30s>
    <x-feedback />
    <x-page-header title="Staff Management" eyebrow="Human Resources" description="Employment records, linked accounts and access status."><x-slot:actions><a class="btn btn-primary" href="{{ route('admin.staff.create') }}"><x-icon name="plus" class="size-4" />Add New Staff</a></x-slot:actions></x-page-header>
    <x-filter-bar name="staff" :count="(int)($query !== '') + (int)($role !== '') + (int)($status !== 'active') + (int)($accountStatus !== '')">
        <x-input name="query" label="Search staff" wire:model.live.debounce.400ms="query" maxlength="200" placeholder="Staff ID, name or email" />
        <x-select name="role" label="System Role" wire:model.live="role"><option value="">All roles</option>@foreach(config('dcms.staff_roles') as $role)@if($role !== 'System Admin' || auth()->user()->can('roles.manage'))<option value="{{ $role }}">{{ $role }}</option>@endif @endforeach</x-select>
        <x-select name="status" label="Staff Status" wire:model.live="status"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></x-select>
<x-slot:advanced><div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">        <x-select name="kind" label="Personnel" wire:model.live="kind"><option value="rider">Riders</option><option value="office">Office staff</option><option value="all">All staff</option></x-select>
        <x-select name="accountStatus" label="Account Status" wire:model.live="accountStatus"><option value="">All accounts</option>@foreach(['invited','active','disabled','locked','none'] as $state)<option value="{{ $state }}">{{ ucfirst($state) }}</option>@endforeach</x-select>
        <x-date-range name="created-date" label="Created date" from="from" until="until" :live="true" />
        <x-select name="sort" label="Sort" wire:model.live="sort"><option value="staff_no">Staff ID</option><option value="name">Name</option><option value="created">Latest created</option></x-select>
        <x-select name="perPage" label="Staff per page" wire:model.live="perPage"><option value="25">25</option><option value="50">50</option><option value="100">100</option></x-select></div></x-slot:advanced>
</x-filter-bar>
    @if($filterError)<p role="alert" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs">{{ $filterError }}</p>@endif
    <x-loading wire:loading.delay wire:target.except="$refresh" label="Loading staff..." />
    @if($staffMembers->isEmpty())<x-empty-state icon="users" title="No staff match these filters" message="Try another search, select All staff, or change Staff Status to include inactive staff." />@else
    <div class="surface overflow-x-auto"><table class="data-table"><caption class="sr-only">Staff directory</caption><thead><tr>@foreach(['Staff ID','Full Name','Email','System Role','Staff Status','Account Status','Created At','Actions'] as $heading)<th scope="col">{{ $heading }}</th>@endforeach</tr></thead>
        <tbody>@foreach($staffMembers as $member)<tr wire:key="staff-{{ $member->public_id }}">
            <td>{{ $member->staff_no }}</td><td>{{ $member->full_name }}</td><td>{{ $member->email }}</td>
            <td>{{ $member->user?->systemRoleLabel() ?: 'Not provisioned' }}</td>
            <td><x-badge :tone="$member->is_active ? 'success' : 'neutral'">{{ $member->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
            <td>{{ $member->user ? ucfirst($member->user->status->value) : 'Not provisioned' }}</td>
            <td>{{ $member->created_at->timezone('Asia/Kuala_Lumpur')->format('d M Y H:i') }}</td>
            <td><div class="flex flex-wrap gap-2"><a class="btn btn-soft-blue btn-sm" href="{{ route('admin.staff.show',['staff'=>$member->public_id]) }}">View</a>@can('update',$member)<a class="btn btn-dark-orange btn-sm" href="{{ route('admin.staff.edit',['staff'=>$member->public_id]) }}">Edit</a>@if($member->is_active)<button type="button" class="btn btn-danger btn-sm" wire:click="confirmDeactivation('{{ $member->public_id }}')" wire:loading.attr="disabled">Deactivate</button>@else<x-confirm-action label="Activate" variant="success" button-class="btn-sm" :title="'Activate '.$member->full_name.'?'" message="This staff member can sign in and receive new work again." :action="'changeAvailability(\''.$member->public_id.'\', true)'" confirm-label="Activate" />@endif @endcan</div></td>
        </tr>@endforeach</tbody></table></div>
    @endif
    {{ $staffMembers->links() }}
    @if($systemAccounts->isNotEmpty())
    <section class="grid gap-3" aria-labelledby="system-accounts-heading">
        <div><h2 id="system-accounts-heading" class="text-base font-semibold">System accounts</h2><p class="mt-1 text-xs text-ink-500">Login accounts that have no staff record, such as the first System Admin. They are not employees in this directory, so they have no staff page to edit.</p></div>
        <div class="surface overflow-x-auto"><table class="data-table"><caption class="sr-only">Login accounts without a staff record</caption><thead><tr>@foreach(['Name', 'Email', 'System Role', 'Account Status', 'Last Login'] as $heading)<th scope="col">{{ $heading }}</th>@endforeach</tr></thead>
            <tbody>@foreach($systemAccounts as $account)<tr wire:key="system-account-{{ $account->id }}">
                <td>{{ $account->name }}@if($account->is(auth()->user())) <x-badge tone="brand">You</x-badge>@endif</td><td>{{ $account->email }}</td>
                <td>{{ $account->systemRoleLabel() ?: 'No role' }}</td>
                <td><x-badge :tone="match ($account->status->value) { 'active' => 'success', 'invited' => 'brand', 'locked' => 'danger', default => 'neutral' }">{{ ucfirst($account->status->value) }}</x-badge></td>
                <td>{{ $account->last_login_at?->timezone('Asia/Kuala_Lumpur')->format('d M Y H:i') ?? 'Never' }}</td>
            </tr>@endforeach</tbody></table></div>
    </section>
    @endif
    @if($deactivatingStaff)
    <dialog wire:key="deactivate-{{ $deactivatingStaff->public_id }}" wire:ignore.self x-data x-init="$el.showModal()" @cancel.prevent="$wire.cancelDeactivation()" aria-label="Deactivate {{ $deactivatingStaff->full_name }}?" class="confirmation-dialog">
        <form wire:submit="changeAvailability('{{ $deactivatingStaff->public_id }}', false)" class="p-6 sm:p-8">
            <div class="text-center"><span class="icon-tile border-red-200 bg-red-50 text-red-800"><x-icon name="info" /></span><h2 class="mt-5 text-xl font-semibold">Deactivate {{ $deactivatingStaff->full_name }}?</h2><p class="mt-3 text-sm leading-6 text-ink-600">Staff ID {{ $deactivatingStaff->staff_no }}. Resolve outstanding allocations first. History is retained and the login account status is not changed.</p></div>
            <div class="mt-5 text-left"><x-textarea name="data.reason" label="Deactivation reason" wire:model="reason" maxlength="1000" required autofocus /></div>
            <div class="mt-7 flex flex-wrap justify-center gap-3"><x-button type="button" variant="secondary" wire:click="cancelDeactivation">Cancel</x-button><x-button variant="danger" wire:loading.attr="disabled" wire:target.except="$refresh">Deactivate</x-button></div>
        </form>
    </dialog>
    @endif
</section>
