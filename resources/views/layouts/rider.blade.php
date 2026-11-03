<!DOCTYPE html>
<html lang="en">
<head>@include('partials.head')</head>
<body class="workspace-canvas text-brand-dark antialiased rider-workspace" x-data="dcmsWorkspace('rider')" :class="{ 'drawer-open': menuOpen }" @resize.window="resize()" @keydown.escape.window="if (menuOpen) closeMenu()" @keydown.window="trap($event)">
<a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:z-50 focus:bg-white focus:p-4">Skip to content</a>
<header :inert="menuOpen" class="rider-header"><div class="rider-header-inner">
    <div class="flex min-w-0 items-center gap-2"><button x-ref="menuToggle" type="button" aria-label="Menu" aria-controls="rider-navigation" :aria-expanded="menuOpen" @click="openMenu()" class="rider-header-control"><x-icon name="menu" /></button><a href="{{ route('rider.home') }}" class="rider-header-brand" aria-label="Nexora Rider home"><x-brand compact /><span class="rider-brand-label">Nexora<span>Rider workspace</span></span></a></div>
    <div class="flex shrink-0 items-center gap-2"><a href="{{ route('rider.notifications') }}" aria-label="Notifications" title="Notifications" class="rider-header-control"><x-icon name="bell" /></a><x-rider.account-menu /></div>
</div></header>
<x-sidebar portal="rider" />
<main :inert="menuOpen" id="main-content" class="rider-main">{{ $slot }}<footer class="rider-page-footer">Nexora · Debt Collection Management System<span>{{ config('dcms.business_timezone') }}</span></footer></main>
<nav :inert="menuOpen" aria-label="Rider navigation" class="rider-bottom-nav rider-primary-nav"><div class="rider-nav-inner">
    <a href="{{ route('rider.home') }}" class="rider-nav-item" @if(request()->routeIs('rider.home')) aria-current="page" @endif><span><x-icon name="home" /></span>Home</a>
    @can('tasks.view')<a href="{{ route('rider.assignments.index') }}" class="rider-nav-item" @if(request()->routeIs('rider.assignments.*')) aria-current="page" @endif><span><x-icon name="layers" /></span>Assignment</a><a href="{{ route('rider.tasks.index') }}" class="rider-nav-item" @if(request()->routeIs('rider.tasks.*')) aria-current="page" @endif><span><x-icon name="clipboard" /></span>My Task</a>@else<span class="rider-nav-item" aria-disabled="true"><span><x-icon name="layers" /></span>Assignment</span><span class="rider-nav-item" aria-disabled="true"><span><x-icon name="clipboard" /></span>My Task</span>@endcan
    @can('attendance.view_own')<a href="{{ route('rider.attendance') }}" class="rider-nav-item" @if(request()->routeIs('rider.attendance', 'rider.attendance.history', 'rider.leave.*')) aria-current="page" @endif><span><x-icon name="clock" /></span>Attendance</a>@else<span class="rider-nav-item" aria-disabled="true"><span><x-icon name="clock" /></span>Attendance</span>@endcan
</div></nav>
@livewireScripts
</body>
</html>
