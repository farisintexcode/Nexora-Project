<?php

/** Screens shared between Riders, Administrative staff and System Admins refresh themselves so each side sees the others' changes. */
test('shared screens refresh themselves without flashing loading banners', function (): void {
    $pages = [
        'admin/home', 'admin/tasks/index', 'admin/tasks/show', 'admin/verification/index',
        'admin/attendance/index', 'admin/attendance/leave-management', 'admin/system-overview', 'admin/staff/index',
        'admin/assignments/index', 'admin/imports/index',
        'rider/home', 'rider/tasks/index', 'rider/notifications', 'rider/attendance', 'rider/leave-requests',
    ];

    foreach ($pages as $page) {
        $view = file_get_contents(resource_path("views/livewire/{$page}.blade.php"));
        expect($view)->toMatch('/<section\b[^>]*wire:poll\.\d+s/', $page);
        preg_match_all('/<[^>]*wire:loading\.delay[^>]*>/', $view, $banners);
        foreach ($banners[0] as $banner) {
            expect($banner)->toContain('wire:target');
        }
    }
});

test('forms being filled in are not refreshed underneath the person typing', function (): void {
    expect(file_get_contents(resource_path('views/livewire/rider/leave-requests.blade.php')))->toContain('@unless($applying) wire:poll')
        ->and(file_get_contents(resource_path('views/livewire/admin/imports/index.blade.php')))->toContain('@unless($file) wire:poll');
});
