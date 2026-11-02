<?php

use App\Http\Controllers\AttendanceExportController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\LetterDownloadController;
use App\Http\Controllers\ProfilePhotoController;
use App\Http\Controllers\ReportDownloadController;
use App\Http\Controllers\SesNotificationController;
use App\Http\Controllers\TaskProofController;
use App\Livewire\Account\Settings;
use App\Livewire\Admin\Assignments\Editor as AssignmentEditor;
use App\Livewire\Admin\Assignments\Index as AssignmentIndex;
use App\Livewire\Admin\Attendance\Configuration;
use App\Livewire\Admin\Calls\FollowUps;
use App\Livewire\Admin\Calls\Show;
use App\Livewire\Admin\Communications\Index;
use App\Livewire\Admin\Communications\Letters;
use App\Livewire\Admin\Communications\Preferences;
use App\Livewire\Admin\Communications\Receipts;
use App\Livewire\Admin\Communications\Recipients;
use App\Livewire\Admin\Home as AdminHome;
use App\Livewire\Admin\Imports\Review;
use App\Livewire\Admin\Staff\Editor as StaffEditor;
use App\Livewire\Admin\Staff\Index as StaffIndex;
use App\Livewire\Admin\Staff\Show as StaffShow;
use App\Livewire\Admin\Tasks\Allocation;
use App\Livewire\Admin\Tasks\Editor;
use App\Livewire\Rider\Attendance;
use App\Livewire\Rider\Home as RiderHome;
use App\Livewire\Rider\Notifications;
use App\Livewire\Rider\Profile;
use App\Livewire\Rider\Tasks\Manage;
use App\Livewire\Rider\Tasks\SubmissionHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');
Route::post('/integrations/ses/{account}/notifications', SesNotificationController::class)->whereUuid('account')->middleware('throttle:ses-receipts')->name('integrations.ses.notifications');
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/rider/login', [SessionController::class, 'create'])->defaults('portal', 'rider')->name('rider.login');
    Route::post('/rider/login', [SessionController::class, 'store'])->defaults('portal', 'rider')->middleware('throttle:login')->name('rider.login.store');
    Route::get('/forgot-password', [PasswordController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordController::class, 'email'])->middleware('throttle:recovery')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordController::class, 'update'])->middleware('throttle:recovery')->name('password.update');
    Route::get('/activate/{token}', [PasswordController::class, 'edit'])->name('activation.edit');
    Route::post('/activate', [PasswordController::class, 'update'])->middleware('throttle:recovery')->name('activation.update');
});

Route::middleware(['auth', 'auth.session'])->group(function (): void {
    Route::get('/account/profile-photo', ProfilePhotoController::class)->middleware('throttle:120,1')->name('account.profile-photo');
    Route::get('/home', function (Request $request) {
        if ($request->user()->can('admin.access')) {
            return redirect()->route('admin.home');
        }
        abort_unless($request->user()->canUseRiderInterface(), 403);

        return redirect()->route('rider.home');
    })->name('home');
    Route::livewire('/admin', AdminHome::class)->middleware('can:admin.access')->name('admin.home');
    Route::middleware(['can:admin.access', 'can:communications.view'])->prefix('admin/communications')->name('admin.communications.')->group(function (): void {
        Route::livewire('/letters', Letters::class)->name('letters');
        Route::get('/letters/{letter}/download', LetterDownloadController::class)->whereUuid('letter')->middleware(['signed', 'throttle:10,1'])->name('letters.download');
        Route::get('/receipts', Receipts::class)->name('receipts');
        Route::livewire('/', Index::class)->name('index');
        Route::livewire('/recipients', Recipients::class)->name('recipients');
        Route::livewire('/preferences/{customer}', Preferences::class)->whereUuid('customer')->name('preferences');
        Route::livewire('/batches/{batch}', App\Livewire\Admin\Communications\Batches\Show::class)->whereUuid('batch')->name('batches.show');
        Route::livewire('/templates/{template}', App\Livewire\Admin\Communications\Templates\Editor::class)->whereUuid('template')->name('templates.edit');
    });
    Route::middleware(['can:admin.access', 'can:calls.record', 'can:customers.view'])->prefix('admin/calls')->name('admin.calls.')->group(function (): void {
        Route::livewire('/', App\Livewire\Admin\Calls\Index::class)->name('index');
        Route::livewire('/follow-ups', FollowUps::class)->name('follow-ups');
        Route::livewire('/{customer}', Show::class)->whereUuid('customer')->name('show');
    });
    Route::middleware(['can:admin.access', 'can:attendance.view_all'])->prefix('admin/attendance')->name('admin.attendance.')->group(function (): void {
        Route::livewire('/', App\Livewire\Admin\Attendance\Index::class)->name('index');
        Route::livewire('/configuration', Configuration::class)->name('configuration');
        Route::get('/export', AttendanceExportController::class)->middleware('throttle:10,1')->name('export');
        Route::livewire('/{schedule}', App\Livewire\Admin\Attendance\Show::class)->whereUuid('schedule')->name('show');
    });
    Route::middleware(['can:admin.access', 'can:reports.view', 'can:tasks.view', 'can:tasks.monitor'])->group(function (): void {
        Route::livewire('/admin/reports', App\Livewire\Admin\Reports\Index::class)->name('admin.reports.index');
        Route::get('/admin/reports/{exportJob}/download', ReportDownloadController::class)->whereUuid('exportJob')->middleware(['can:reports.export', 'signed', 'throttle:10,1'])->name('admin.reports.download');
    });
    Route::middleware(['can:admin.access', 'can:tasks.verify'])->prefix('admin/verification')->name('admin.verification.')->group(function (): void {
        Route::livewire('/', App\Livewire\Admin\Verification\Index::class)->name('index');
        Route::livewire('/{submission}', App\Livewire\Admin\Verification\Show::class)->whereUuid('submission')->name('show');
    });
    Route::middleware(['can:admin.access', 'can:tasks.view'])->prefix('admin/tasks')->name('admin.tasks.')->group(function (): void {
        Route::livewire('/', App\Livewire\Admin\Tasks\Index::class)->name('index');
        Route::livewire('/create', Editor::class)->name('create');
        Route::livewire('/allocate', Allocation::class)->name('allocate');
        Route::livewire('/operations/{operation}', Allocation::class)->whereUuid('operation')->name('operation');
        Route::livewire('/{task}/allocate', Allocation::class)->whereUuid('task')->name('allocate-one');
        Route::livewire('/{task}/edit', Editor::class)->whereUuid('task')->name('edit');
        Route::livewire('/{task}', App\Livewire\Admin\Tasks\Show::class)->whereUuid('task')->name('show');
    });
    Route::middleware(['can:admin.access', 'can:imports.view'])->prefix('admin/imports')->name('admin.imports.')->group(function (): void {
        Route::livewire('/', App\Livewire\Admin\Imports\Index::class)->name('index');
        Route::livewire('/{import}', Review::class)->whereUuid('import')->name('review');
    });
    Route::middleware(['can:admin.access', 'can:assignments.view'])->prefix('admin/assignments')->name('admin.assignments.')->group(function (): void {
        Route::livewire('/', AssignmentIndex::class)->name('index');
        Route::livewire('/create', AssignmentEditor::class)->middleware('can:assignments.manage')->name('create');
        Route::livewire('/{batch}/edit', AssignmentEditor::class)->whereUuid('batch')->name('edit');
    });
    Route::middleware(['can:admin.access', 'can:riders.manage'])->prefix('admin/staff')->name('admin.staff.')->group(function (): void {
        Route::livewire('/', StaffIndex::class)->name('index');
        Route::livewire('/create', StaffEditor::class)->name('create');
        Route::livewire('/{staff}/edit', StaffEditor::class)->whereUuid('staff')->name('edit');
        Route::livewire('/{staff}', StaffShow::class)->whereUuid('staff')->name('show');
    });
    Route::livewire('/rider', RiderHome::class)->middleware('can:use-rider-interface')->name('rider.home');
    Route::livewire('/rider/profile', Profile::class)->middleware('can:use-rider-interface')->name('rider.profile');
    Route::middleware(['can:use-rider-interface', 'can:tasks.view'])->prefix('rider/assignments')->name('rider.assignments.')->group(function (): void {
        Route::livewire('/', App\Livewire\Rider\Assignments\Index::class)->name('index');
        Route::livewire('/{batch}', App\Livewire\Rider\Assignments\Show::class)->whereUuid('batch')->name('show');
    });
    Route::livewire('/rider/notifications', Notifications::class)->middleware('can:use-rider-interface')->name('rider.notifications');
    Route::livewire('/rider/attendance', Attendance::class)->middleware(['can:use-rider-interface', 'can:attendance.view_own'])->name('rider.attendance');
    Route::livewire('/attendance', Attendance::class)->middleware('can:attendance.view_own')->name('attendance.own');
    Route::middleware(['can:use-rider-interface', 'can:tasks.view'])->prefix('rider/tasks')->name('rider.tasks.')->group(function (): void {
        Route::livewire('/manage', Manage::class)->middleware('can:tasks.submit')->name('manage');
        Route::livewire('/', App\Livewire\Rider\Tasks\Index::class)->name('index');
        Route::livewire('/history/{submission}', SubmissionHistory::class)->whereUuid('submission')->name('history');
        Route::livewire('/{task}', App\Livewire\Rider\Tasks\Show::class)->whereUuid('task')->name('show');
    });
    Route::get('/proof/{document}', TaskProofController::class)->whereUuid('document')->middleware('throttle:60,1')->name('proof.show');
    Route::livewire('/account', Settings::class)->name('account.settings');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
});
