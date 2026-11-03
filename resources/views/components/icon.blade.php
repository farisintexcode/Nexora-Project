@props(['name' => 'grid'])
<svg {{ $attributes->class(['size-5 shrink-0'])->merge(['viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.7', 'stroke-linecap' => 'round', 'stroke-linejoin' => 'round', 'aria-hidden' => 'true']) }}>
@switch($name)
    @case('edit')<path d="m16 3 5 5M4 20l5-1L21 7a2.1 2.1 0 0 0-5-3L4 16l-1 5Z"/>@break
    @case('bike')<circle cx="5" cy="17" r="4"/><circle cx="19" cy="17" r="4"/><path d="m5 17 5-9 5 9H5m5-9h5l4 9M8 5h4m3-2h3l2 5"/>@break
    @case('bell')<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>@break
    @case('layers')<path d="m3 7 9-5 9 5-9 5-9-5Zm0 5 9 5 9-5M3 17l9 5 9-5"/>@break
    @case('moon')<path d="M20.5 14A9 9 0 0 1 10 3.5 9 9 0 1 0 20.5 14Z"/>@break
    @case('sun')<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5 19 19M5 19l1.5-1.5M17.5 6.5 19 5"/>@break
    @case('panel')<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M9 3v18m4-12 3 3-3 3"/>@break
    @case('upload')<path d="M4 16v4h16v-4M12 16V3m-5 5 5-5 5 5"/>@break
    @case('clipboard')<rect x="5" y="5" width="14" height="16" rx="2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="m9 14 2 2 4-5"/>@break
    @case('phone')<path d="m4 3 4 1 1 5-2 2a16 16 0 0 0 6 6l2-2 5 1 1 4c-8 5-23-10-17-17Z"/>@break
    @case('settings')<path d="m9 3-1 3-3 1-2 3 2 2v3l3 2 1 4h6l1-4 3-2v-3l2-2-2-3-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/>@break
    @case('home')<path d="m3 10 9-7 9 7v10H3Z M9 20v-7h6v7"/>@break
    @case('users')<circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2 M17 5a3 3 0 0 1 0 6 M21 21v-2a6 6 0 0 0-3-5"/>@break
    @case('user')<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>@break
    @case('arrow-right')<path d="M4 12h16m-6-6 6 6-6 6"/>@break
    @case('arrow-left')<path d="M20 12H4m6-6-6 6 6 6"/>@break
    @case('chevron')<path d="m9 5 7 7-7 7"/>@break
    @case('sort')<path d="m8 9 4-4 4 4M16 15l-4 4-4-4"/>@break
    @case('more')<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>@break
    @case('plus')<path d="M12 5v14M5 12h14"/>@break
    @case('search')<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>@break
    @case('filter')<path d="M3 6h18M6 12h12M9 18h6 M7 4v4M16 10v4M12 16v4"/>@break
    @case('shield')<path d="m12 3 8 3v6c0 4-4 7-8 9-4-2-8-5-8-9V6Z m-4 9 3 3 5-6"/>@break
    @case('check')<path d="m5 12 4 4L19 6"/>@break
    @case('clock')<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>@break
    @case('briefcase')<rect x="3" y="7" width="18" height="14" rx="3"/><path d="M8 7V4h8v3M3 12a22 22 0 0 0 18 0M12 12v3"/>@break
    @case('pin')<path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>@break
    @case('calendar')<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4M17 3v4M3 11h18m-13 5h2m4 0h2"/>@break
    @case('activity')<path d="M2 12h5l3-8 4 16 3-8h5"/>@break
    @case('mail')<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m3 6 9 7 9-7"/>@break
    @case('eye')<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>@break
    @case('lock')<rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3 M12 14v3"/>@break
    @case('logout')<path d="M9 4H4v16h5m5-12 4 4-4 4m-5-4h12"/>@break
    @case('menu')<path d="M4 6h16M4 12h16M4 18h16"/>@break
    @case('close')<path d="m6 6 12 12M6 18 18 6"/>@break
    @case('tag')<path d="M3 3h8l10 10-8 8L3 11Z"/><circle cx="7" cy="7" r="1"/>@break
    @case('info')<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7v.01"/>@break
    @case('chart')<path d="M4 3v18h17M9 17v-6M14 17V7M19 17V4"/>@break
    @default<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>
@endswitch
</svg>
