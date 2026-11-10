<section class="hr-page grid gap-6">
    <x-feedback />
    <x-page-header title="Staff Management" eyebrow="Human Resources" description="Employment records, linked accounts and access status."><x-slot:actions><a class="btn btn-primary" href="{{ route('admin.staff.create') }}"><x-icon name="plus" />Add New Staff</a></x-slot:actions></x-page-header>
    <x-filter-bar name="staff" :count="(int)($query !== '') + (int)($role !== '') + (int)($status !== '') + (int)($accountStatus !== '')">
        <x-input name="query" label="Search staff" wire:model.live.debounce.400ms="query" maxlength="200" placeholder="Staff ID, name or email" />
        <x-select name="role" label="System Role" wire:model.live="role"><option value="">All roles</option>@foreach(config('dcms.staff_roles') as $role)<option value="{{ $role }}">{{ $role }}</option>@endforeach</x-select>
        <x-select name="status" label="Staff Status" wire:model.live="status"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></x-select>
<x-slot:advanced><div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">        <x-select name="kind" label="Personnel" wire:model.live="kind"><option value="rider">Riders</option><option value="office">Office staff</option><option value="all">All staff</option></x-select>
        <x-select name="accountStatus" label="Account Status" wire:model.live="accountStatus"><option value="">All accounts</option>@foreach(['invited','active','disabled','locked','none'] as $state)<option value="{{ $state }}">{{ ucfirst($state) }}</option>@endforeach</x-select>
        <x-date-range name="created-date" label="Created date" from="from" until="until" :live="true" />
        <x-select name="sort" label="Sort" wire:model.live="sort"><option value="staff_no">Staff ID</option><option value="name">Name</option><option value="created">Latest created</option></x-select>
        <x-select name="perPage" label="Staff per page" wire:model.live="perPage"><option value="25">25</option><option value="50">50</option><option value="100">100</option></x-select></div></x-slot:advanced>
</x-filter-bar>
<div class="surface p-5"><x-input name="reason" label="Deactivation reason" wire:model="reason" maxlength="1000" help="Resolve outstanding allocations before disabling staff. History is retained." /></div>
    @if($filterError)<p role="alert">{{ $filterError }}</p>@endif
    <x-loading wire:loading.delay label="Loading staff..." />
    <p class="text-xs text-ink-500">{{ $staffMembers->total() }} matching staff records. Timestamps use Asia/Kuala_Lumpur.</p>
    @if($staffMembers->isEmpty())<x-empty-state icon="users" title="No staff match these filters" message="Try another search or select All staff." />@else
    <div class="surface overflow-x-auto"><table class="data-table"><caption class="sr-only">Staff directory</caption><thead><tr>@foreach(['Staff ID','Full Name','Email','System Role','Staff Status','Account Status','Created At','Actions'] as $heading)<th scope="col">{{ $heading }}</th>@endforeach</tr></thead>
        <tbody>@foreach($staffMembers as $member)<tr wire:key="staff-{{ $member->public_id }}">
            <td>{{ $member->staff_no }}</td><td>{{ $member->full_name }}</td><td>{{ $member->email }}</td>
            <td>{{ $member->user?->systemRoleLabel() ?: 'Not provisioned' }}</td>
            <td><x-badge :tone="$member->is_active ? 'success' : 'neutral'">{{ $member->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
            <td>{{ $member->user ? ucfirst($member->user->status->value) : 'Not provisioned' }}</td>
            <td>{{ $member->created_at->timezone('Asia/Kuala_Lumpur')->format('d M Y H:i') }}</td>
            <td><div class="flex flex-nowrap gap-2"><a class="btn btn-secondary btn-sm" href="{{ route('admin.staff.show',['staff'=>$member->public_id]) }}"><x-icon name="eye" />View</a>@can('update',$member)<a class="btn btn-secondary btn-sm" href="{{ route('admin.staff.edit',['staff'=>$member->public_id]) }}"><x-icon name="edit" />Edit</a><button type="button" @class(['btn btn-sm', 'btn-secondary' => ! $member->is_active, 'btn-danger' => $member->is_active]) wire:click="changeAvailability('{{ $member->public_id }}', {{ $member->is_active ? 'false' : 'true' }})" wire:confirm="Change staff availability?" wire:loading.attr="disabled">{{ $member->is_active ? 'Deactivate' : 'Activate' }}</button>@endcan</div></td>
        </tr>@endforeach</tbody></table></div>
    @endif
    {{ $staffMembers->links() }}
</section>
