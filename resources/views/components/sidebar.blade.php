@props(['portal' => 'admin'])
<button type="button" x-cloak x-show="menuOpen && !desktop" @click="closeMenu()" @class(['fixed inset-0 z-30 bg-brand-primary/40 backdrop-blur-[2px]', 'lg:hidden' => $portal === 'admin']) aria-label="Close navigation"></button>
<aside x-ref="drawer" id="{{ $portal }}-navigation" class="dcms-sidebar" inert :class="{ 'is-open': menuOpen }" :inert="!desktop && !menuOpen" :role="desktop ? 'complementary' : 'dialog'" :aria-modal="!desktop && menuOpen ? 'true' : null" aria-label="{{ ucfirst($portal) }} sidebar" @touchstart.passive="touchStart = $event.touches[0].clientX" @touchend.passive="swipe($event)">
    <div class="sidebar-brand"><a href="{{ route($portal.'.home') }}" aria-label="Nexora home" class="flex min-h-12 items-center gap-3"><x-brand /></a><button type="button" @class(['sidebar-mobile-close grid size-11 shrink-0 place-items-center rounded-xl border border-ink-200', 'lg:hidden' => $portal === 'admin']) x-ref="closeMenu" aria-label="Close menu" @click="closeMenu()"><x-icon name="close" /></button></div>
    <div class="sidebar-search"><button type="button" class="sidebar-search-icon sidebar-link" aria-label="Search navigation" @click="searchMenu()" @mouseenter="hint($event, 'Search navigation')" @mouseleave="tooltip = ''" @focus="hint($event, 'Search navigation')" @blur="tooltip = ''"><x-icon name="search" /></button><label class="sidebar-search-field"><x-icon name="search" class="size-4 text-ink-500" /><span class="sr-only">Search navigation</span><input x-ref="navigationSearch" type="search" placeholder="Find a page…" x-model="query" maxlength="100" autocomplete="off" @keydown.escape.stop="if (query) query = ''; else if (!desktop) closeMenu()"></label></div>
    <div class="sidebar-scroll"><nav x-ref="navigationItems" aria-label="{{ $portal === 'rider' ? 'Rider menu' : 'Admin navigation' }}" class="grid gap-1.5">
    @if($portal === 'admin')
        @php($pending = app(\App\Services\PendingReviews::class)->counts(auth()->user()))
        <x-sidebar-item label="Dashboard" icon="grid" :href="route('admin.home')" :active="request()->routeIs('admin.home', 'admin.system')" />
        @can('assignments.view')
        <x-sidebar-group label="Assignment" icon="briefcase" :active="request()->routeIs('admin.assignments.*')" keywords="Manage Assignment Add Assignment Active Archived batches">
            <x-sidebar-item label="Manage Assignment" icon="briefcase" parent="Assignment" :href="route('admin.assignments.index')" :active="request()->routeIs('admin.assignments.*') && !request()->routeIs('admin.assignments.create')" />
            @can('assignments.manage')<x-sidebar-item label="Add Assignment" icon="plus" parent="Assignment" :href="route('admin.assignments.create')" :active="request()->routeIs('admin.assignments.create')" />@endcan
        </x-sidebar-group>
        @endcan
        @canany(['imports.view', 'tasks.monitor', 'tasks.verify', 'reports.view'])
        <x-sidebar-group label="Task Management" icon="clipboard" :count="$pending['tasks']" count-key="tasks" :active="request()->routeIs('admin.imports.*', 'admin.tasks.*', 'admin.verification.*', 'admin.reports.*')" keywords="Upload Task Excel imports Assign Task allocation Manage Task monitoring Task Verification Report">
            @can('imports.view')<x-sidebar-item label="Upload Task" icon="upload" parent="Task Management" :href="route('admin.imports.index')" :active="request()->routeIs('admin.imports.*')" />@endcan
            @can('tasks.monitor')
            <x-sidebar-item label="Assign Task" icon="users" parent="Task Management" :href="route('admin.tasks.allocate')" :active="request()->routeIs('admin.tasks.allocate', 'admin.tasks.allocate-one')" />
            <x-sidebar-item label="Manage Task" icon="clipboard" parent="Task Management" :href="route('admin.tasks.index')" :active="request()->routeIs('admin.tasks.*') && !request()->routeIs('admin.tasks.allocate', 'admin.tasks.allocate-one')" />
            @endcan
            @can('tasks.verify')<x-sidebar-item label="Task Verification" icon="shield" :count="$pending['tasks']" count-key="tasks" parent="Task Management" :href="route('admin.verification.index')" :active="request()->routeIs('admin.verification.*')" />@endcan
            @can('reports.view')<x-sidebar-item label="Report" icon="chart" parent="Task Management" :href="route('admin.reports.index')" :active="request()->routeIs('admin.reports.*')" />@endcan
        </x-sidebar-group>
        @endcanany
        @canany(['riders.manage', 'attendance.view_all', 'attendance.view_own'])
        <x-sidebar-group label="Human Resources" icon="users" :count="$pending['leave']" count-key="leave" :active="request()->routeIs('admin.staff.*', 'admin.attendance.*', 'admin.leave.*', 'attendance.own')" keywords="Staff Management Riders personnel Add New Staff Attendance My attendance schedules">
            @can('riders.manage')
            <x-sidebar-item label="Staff Management" icon="users" parent="Human Resources" :href="route('admin.staff.index')" :active="request()->routeIs('admin.staff.*') && !request()->routeIs('admin.staff.create')" />
            <x-sidebar-item label="Add New Staff" icon="plus" parent="Human Resources" :href="route('admin.staff.create')" :active="request()->routeIs('admin.staff.create')" />
            @endcan
            @can('attendance.view_all')<x-sidebar-item label="Attendance" icon="clock" parent="Human Resources" :href="route('admin.attendance.index')" :active="request()->routeIs('admin.attendance.*')" />@endcan
            @can('attendance.view_all')<x-sidebar-item label="Leave Management" icon="calendar" :count="$pending['leave']" count-key="leave" parent="Human Resources" :href="route('admin.leave.index')" :active="request()->routeIs('admin.leave.*')" />@endcan
            @can('attendance.view_own')<x-sidebar-item label="My attendance" icon="clock" parent="Human Resources" :href="route('attendance.own')" :active="request()->routeIs('attendance.own')" />@endcan
        </x-sidebar-group>
        @endcanany
        @can('monitoring.view')<x-sidebar-item label="System Health" icon="activity" :href="url('/pulse')" />@endcan
        <div class="sidebar-divider" x-show="!query.trim()"></div>
        <p class="sidebar-section sidebar-label" x-show="!query.trim()">Supporting modules</p>
        @can('viewAny',\App\Models\CollectionActivity::class)<x-sidebar-item label="Call Centre" tag="Upcoming" icon="phone" :href="route('admin.calls.index')" :active="request()->routeIs('admin.calls.*')" />@endcan
        @can('viewAny',\App\Models\CommunicationTemplate::class)<x-sidebar-item label="Communications" tag="Upcoming" icon="mail" :href="route('admin.communications.index')" :active="request()->routeIs('admin.communications.*')" />@endcan
    @else
        <div class="rider-menu-profile sidebar-label"><span class="rider-avatar"><x-profile-photo /></span><div class="min-w-0"><p class="font-semibold">{{ auth()->user()->name }}</p><p class="mt-1 break-all text-xs">{{ auth()->user()->email }}</p></div></div>
        <p class="sidebar-section sidebar-label" x-show="!query.trim()">Your workspace</p>
        <x-sidebar-item label="Home" icon="home" :href="route('rider.home')" :active="request()->routeIs('rider.home')" />
        @can('tasks.view')<x-sidebar-item label="Assignment" icon="layers" :href="route('rider.assignments.index')" :active="request()->routeIs('rider.assignments.*')" />@endcan
        @can('tasks.view')<x-sidebar-item label="My Task" icon="clipboard" :href="route('rider.tasks.index')" :active="request()->routeIs('rider.tasks.*')" />@endcan
        @can('attendance.view_own')<x-sidebar-item label="Attendance" icon="clock" :href="route('rider.attendance')" :active="request()->routeIs('rider.attendance', 'rider.attendance.history', 'rider.leave.*')" />@endcan
        <div class="sidebar-divider" x-show="!query.trim()"></div>
        <x-sidebar-item label="Notifications" icon="bell" :href="route('rider.notifications')" :active="request()->routeIs('rider.notifications')" />
        <div class="rider-menu-appearance sidebar-label"><span>Appearance</span><x-theme-toggle /></div>
    @endif
    </nav><p x-cloak x-show="query.trim() && ![...$refs.navigationItems.querySelectorAll('[data-nav-search]')].some(el => matches(el.dataset.navSearch))" class="sidebar-label px-3 py-6 text-xs leading-6 text-ink-500" role="status">No matching pages. Try another menu name.</p></div>
    <div @class(['sidebar-footer', 'rider-side-footer' => $portal === 'rider'])><span class="sidebar-label text-[10px] leading-5 text-ink-500">Nexora<br>Debt Collection Management System</span>@if($portal === 'admin')<button type="button" class="sidebar-collapse grid size-11 shrink-0 place-items-center rounded-xl border border-white/20 hover:bg-white/10" :aria-label="rail ? 'Expand sidebar' : 'Collapse sidebar'" :aria-expanded="!rail" aria-controls="admin-navigation" @click="toggleCompact()"><x-icon name="panel" /></button>@endif</div>
</aside>
<x-sidebar-tooltip />

