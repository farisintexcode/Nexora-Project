<section class="grid gap-6" wire:poll.30s>
    <x-feedback />
    <x-page-header title="Assignment" eyebrow="Recovery work packages" description="Organize assignments, schedules and routing metadata before importing tasks."><x-slot:actions>@can('create', \App\Models\AssignmentBatch::class)<a class="btn btn-primary" href="{{ route('admin.assignments.create') }}"><x-icon name="plus" class="size-4" />New assignment</a>@endcan</x-slot:actions></x-page-header>
    <nav aria-label="Assignment register" class="segment-tabs"><a class="segment-tab" href="{{ route('admin.assignments.index') }}" @if($status === 'active') aria-current="page" @endif>Active assignments</a><a class="segment-tab" href="{{ route('admin.assignments.index', ['status'=>'overdue']) }}" @if($status === 'overdue') aria-current="page" @endif>Overdue assignments</a><a class="segment-tab" href="{{ route('admin.assignments.index', ['status'=>'archived']) }}" @if($status === 'archived') aria-current="page" @endif>Archived assignments</a></nav>
    <div class="assignment-register grid min-w-0 gap-4">
        <form class="surface grid gap-5 p-4 sm:p-5" wire:submit="applyFilters" data-start-open="{{ $activeFilterCount > 0 ? '1' : '0' }}" x-data="{ filtersOpen: $el.dataset.startOpen === '1' }">
            <div class="flex flex-col gap-3 sm:flex-row">
                <div class="relative min-w-0 flex-1">
                    <label for="query" class="sr-only">Search assignments</label>
                    <x-icon name="search" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-ink-500" />
                    <input id="query" name="query" type="search" class="field pl-11" wire:model.live.debounce.400ms="query" placeholder="Search by ID, name or external reference..." maxlength="200" autocomplete="off">
                </div>
                <x-filter-toggle controls="assignment-filters" :count="$activeFilterCount" />
            </div>
            <div id="assignment-filters" class="grid gap-5 border-t border-ink-100 pt-5" x-show="filtersOpen" x-cloak>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-select name="filter-type" label="Assignment type" wire:model="draft.type"><option value="">All types</option>@foreach($types as $item)<option value="{{ $item->code }}">{{ $item->label }}</option>@endforeach</x-select>
                    <x-select name="filter-state" label="State" wire:model="draft.state"><option value="">All states</option>@foreach($states as $item)<option value="{{ $item->code }}">{{ $item->label }}</option>@endforeach</x-select>
                    <x-date-range name="filter-date" label="Assignment Date" from="draft.from" until="draft.until" />
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <button type="button" class="min-h-11 text-xs font-semibold text-ink-700 underline decoration-ink-300 underline-offset-4 hover:decoration-brand-accent" wire:click="resetFilters">Clear all filters</button>
                    <div class="flex flex-wrap gap-3">
                        <button type="button" class="btn btn-secondary min-h-11 py-2" wire:click="resetAdvancedFilters">Reset</button>
                        <button type="submit" class="btn assignment-apply min-h-11 py-2">Apply filters</button>
                    </div>
                </div>
            </div>
        </form>
        @if($filterError)<p role="alert" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs">{{ $filterError }}</p>@endif
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-ink-600" aria-live="polite">Showing {{ $batches->firstItem() ?? 0 }} to {{ $batches->lastItem() ?? 0 }} of {{ $batches->total() }} entries</p>
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2"><label for="sort" class="field-label whitespace-nowrap">Sort by</label><select id="sort" name="sort" class="field min-h-10 w-auto py-2 text-sm" wire:model.live="sort"><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="name_asc">Name ascending</option><option value="name_desc">Name descending</option></select></div>
                <div class="flex items-center gap-2"><label for="perPage" class="field-label whitespace-nowrap">Per page</label><select id="perPage" name="perPage" class="field min-h-10 w-auto py-2 text-sm" wire:model.live="perPage">@foreach(['10', '25', '50', '100'] as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach</select></div>
            </div>
        </div>
        <x-loading wire:loading.delay wire:target.except="$refresh" label="Loading assignments…" />
        @if($batches->isEmpty())
            <x-empty-state icon="briefcase" title="No assignments found" message="Try adjusting your search or filters to find what you're looking for."><button type="button" class="btn btn-secondary" wire:click="resetFilters">Clear filters</button></x-empty-state>
        @else
            @php
                $idSort = ['newest' => 'descending', 'oldest' => 'ascending'][$sort] ?? null;
                $nameSort = ['name' => 'ascending', 'name_asc' => 'ascending', 'name_desc' => 'descending'][$sort] ?? null;
            @endphp
            <div class="surface overflow-x-auto">
                <table class="data-table assignment-table">
                    <caption class="sr-only">Scoped assignment register</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col" aria-sort="{{ $idSort ?? 'none' }}"><button type="button" class="assignment-sort" wire:click="sortBy('id')">ID<x-icon :name="$idSort ? 'chevron' : 'sort'" @class(['size-3.5', '-rotate-90' => $idSort === 'ascending', 'rotate-90' => $idSort === 'descending', 'opacity-50' => ! $idSort]) /></button></th>
                            <th scope="col" aria-sort="{{ $nameSort ?? 'none' }}"><button type="button" class="assignment-sort" wire:click="sortBy('name')">Name<x-icon :name="$nameSort ? 'chevron' : 'sort'" @class(['size-3.5', '-rotate-90' => $nameSort === 'ascending', 'rotate-90' => $nameSort === 'descending', 'opacity-50' => ! $nameSort]) /></button></th>
                            <th scope="col">Date</th>
                            <th scope="col">Type</th>
                            <th scope="col" class="text-right">Upload</th>
                            <th scope="col" class="text-right">Assign</th>
                            <th scope="col" class="text-right">Complete</th>
                            <th scope="col" class="text-right">Payment Update</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="relative"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($batches as $batch)
                            <tr wire:key="batch-row-{{ $batch->public_id }}">
                                <td class="text-ink-500 tabular-nums">{{ $batches->firstItem() + $loop->index }}</td>
                                <td class="whitespace-nowrap tabular-nums">{{ $batch->assignment_code }}</td>
                                <td class="assignment-name"><a class="font-semibold underline" href="{{ route('admin.assignments.edit', ['batch' => $batch->public_id]) }}">{{ $batch->name }}</a></td>
                                <td class="whitespace-nowrap">{{ $batch->start_date->format('d/m/Y') }} to {{ $batch->due_date->format('d/m/Y') }}</td>
                                <td class="whitespace-nowrap">{{ $batch->type?->label ?? '-' }}</td>
                                @foreach(['upload_count', 'assigned_count', 'completed_count', 'payment_update_count'] as $count)
                                    <td class="text-right tabular-nums">{{ $batch->{$count} ?: '-' }}</td>
                                @endforeach
                                @php($isOverdue = $batch->status->value === 'active' && $batch->due_date->toDateString() < $today)
                                <td><x-badge :tone="$isOverdue ? 'danger' : ($batch->status->value === 'active' ? 'success' : 'neutral')">{{ $isOverdue ? 'Overdue' : ucfirst($batch->status->value) }}</x-badge></td>
                                <td class="text-right">
                                    <div x-data="{ open: false }" x-on:keydown.escape="open = false">
                                        <button type="button" x-ref="trigger" class="assignment-menu-trigger" x-on:click="open = !open" x-bind:aria-expanded="open" aria-haspopup="menu" aria-label="Actions for {{ $batch->name }}"><x-icon name="more" class="size-4" /></button>
                                        <div class="assignment-menu" role="menu" x-show="open" x-cloak x-anchor.bottom-end.offset.4="$refs.trigger" x-on:click.outside="open = false">
                                            <a role="menuitem" href="{{ route('admin.assignments.edit', ['batch' => $batch->public_id]) }}">View assignment</a>
                                            @if($canViewTasks)<a role="menuitem" href="{{ route('admin.tasks.index', ['batch' => $batch->public_id]) }}">View tasks</a>@endif
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        {{ $batches->links() }}
    </div>
</section>
