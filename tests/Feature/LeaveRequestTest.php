<?php

use App\Actions\Attendance\ClockAttendance;
use App\Actions\Attendance\CorrectAttendance;
use App\Actions\Attendance\ManageLeave;
use App\Livewire\Admin\Attendance\LeaveManagement;
use App\Livewire\Rider\Attendance as RiderAttendance;
use App\Livewire\Rider\LeaveRequests;
use App\Models\Attendance;
use App\Models\AttendanceSchedule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Models\User;
use App\Notifications\LeaveUpdated;
use App\Services\Attendance\AttendanceRegister;
use App\Services\Imports\MalwareScanner;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
    $this->travelTo(now()->timezone('Asia/Kuala_Lumpur')->setTime(8, 0));
});

function leaveFixture(): array
{
    $rider = User::factory()->for(Staff::factory()->create(['is_rider' => true]))->create();
    $rider->assignRole('Rider');
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $admin->givePermissionTo('attendance.approve_correction');
    $type = LeaveType::factory()->create(['configured_by' => $admin->id, 'name' => 'Approved Test Leave', 'allow_pending_cancel' => true]);
    $start = now()->addDays(3)->timezone('Asia/Kuala_Lumpur')->toDateString();
    $schedule = AttendanceSchedule::factory()->create(['rider_id' => $rider->staff_id, 'service_date' => $start, 'scheduled_start_at' => CarbonImmutable::parse($start.' 09:00', 'Asia/Kuala_Lumpur')->utc(), 'scheduled_end_at' => CarbonImmutable::parse($start.' 18:00', 'Asia/Kuala_Lumpur')->utc(), 'authorized_by' => $admin->id]);

    return [$rider, $admin, $type, ['type' => $type->public_id, 'start_date' => $start, 'end_date' => $start, 'reason' => 'Private medical or family reason'], $schedule];
}
function submitLeave(User $rider, array $data, ?UploadedFile $file = null): LeaveRequest
{
    $action = app(ManageLeave::class);
    $preview = $action->preview($rider, $data, $file);

    return $action->submit($rider, $data, $preview['token'], $file);
}

function changeLeaveCalendar(User $rider, User $admin, AttendanceSchedule $schedule): void
{
    $rider->givePermissionTo('attendance.correct');
    $corrections = app(CorrectAttendance::class);
    $correction = $corrections->request($rider, $schedule, ['day_type' => 'day_off', 'reason' => 'Approved calendar change'], $corrections->snapshot($schedule, null));
    $corrections->review($admin, $correction, 'approved', 'Independent calendar review');
}
test('rider reviews submits and admin independently approves without changing attendance', function (): void {
    [$rider,$admin,$type,$data,$schedule] = leaveFixture();
    $this->actingAs($rider)->get(route('rider.leave.index'))->assertOk()->assertSee('My Leave Requests');
    $this->get(route('rider.leave.apply'))->assertOk()->assertSee('Reason for Leave');
    $action = app(ManageLeave::class);
    $preview = $action->preview($rider, $data);
    expect($preview['total_days'])->toBe(1);
    expect(LeaveRequest::count())->toBe(0);
    $request = $action->submit($rider, $data, $preview['token']);
    expect($request->status)->toBe('pending')->and($request->reason)->toBe($data['reason']);
    expect(DB::table('leave_requests')->value('reason'))->not->toBe($data['reason']);
    expect($request->schedules()->count())->toBe(1);
    expect($admin->notifications()->where('type', LeaveUpdated::class)->count())->toBe(1);
    $this->get(route('rider.leave.show', $request))->assertOk()->assertSee($data['reason']);
    $this->actingAs($admin)->get(route('admin.leave.index'))->assertOk()->assertSee($type->name);
    $this->get(route('admin.leave.show', $request))->assertOk()->assertSee('Confirm Decision');
    $action->decide($admin, $request, 'approved', 'Approved by independent reviewer', 0);
    expect($request->fresh()->status)->toBe('approved')->and($schedule->fresh()->day_type)->toBe('scheduled')->and(Attendance::count())->toBe(0);
    expect($rider->notifications()->where('type', LeaveUpdated::class)->count())->toBe(1);
    $this->actingAs($rider)->get(route('rider.notifications'))->assertOk()->assertSee('View Leave Request');
    $this->assertDatabaseHas('audit_logs', ['action' => 'leave.approved', 'actor_id' => $admin->id]);
});

test('replayed confirmations are idempotent and overlapping requests cannot be submitted', function (): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    $action = app(ManageLeave::class);
    $review = $action->preview($rider, $data);
    $first = $action->submit($rider, $data, $review['token']);
    $second = $action->submit($rider, $data, $review['token']);
    expect($first->id)->toBe($second->id)->and(LeaveRequest::count())->toBe(1);
    expect(fn () => $action->preview($rider, $data))->toThrow(ValidationException::class);
});

test('rest days and public holidays are excluded and missing calendars block requests', function (): void {
    [$rider,$admin,$type,$data,$schedule] = leaveFixture();
    $end = CarbonImmutable::parse($data['start_date'])->addDays(2);
    foreach (['day_off', 'public_holiday'] as $index => $kind) {
        AttendanceSchedule::factory()->create(['rider_id' => $rider->staff_id, 'service_date' => CarbonImmutable::parse($data['start_date'])->addDays($index + 1)->toDateString(), 'day_type' => $kind, 'scheduled_start_at' => null, 'scheduled_end_at' => null]);
    }
    $data['end_date'] = $end->toDateString();
    $preview = app(ManageLeave::class)->preview($rider, $data);
    expect($preview['total_days'])->toBe(1)->and($preview['excluded_days'])->toBe(2);
    $data['end_date'] = $end->addDay()->toDateString();
    expect(fn () => app(ManageLeave::class)->preview($rider, $data))->toThrow(ValidationException::class);
});

test('changed application calendar or policy invalidates the review', function (string $change): void {
    [$rider,$admin,$type,$data,$schedule] = leaveFixture();
    $action = app(ManageLeave::class);
    $preview = $action->preview($rider, $data);
    if ($change === 'data') {
        $data['reason'] = 'Changed after reviewing';
    }
    if ($change === 'calendar') {
        changeLeaveCalendar($rider, $admin, $schedule);
    }
    if ($change === 'policy') {
        $type->forceFill(['lock_version' => 1])->save();
    }
    if ($change === 'expiry') {
        $this->travel(16)->minutes();
    }
    expect(fn () => $action->submit($rider, $data, $preview['token']))->toThrow(ValidationException::class);
    expect(LeaveRequest::count())->toBe(0);
})->with(['data', 'calendar', 'policy', 'expiry']);

test('riders cannot view other applications or admin routes or supporting documents', function (): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    $request = submitLeave($rider, $data);
    $other = User::factory()->for(Staff::factory()->create(['is_rider' => true]))->create();
    $other->assignRole('Rider');
    $this->actingAs($other)->get(route('rider.leave.show', $request))->assertNotFound();
    $this->get(route('leave.document', $request))->assertNotFound();
    $this->get(route('admin.leave.index'))->assertForbidden();
    expect(fn () => app(ManageLeave::class)->cancel($other, $request, 0))->toThrow(HttpException::class);
});

test('approval requires a separate permission and rejects self review', function (): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    $request = submitLeave($rider, $data);
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(['admin.access', 'attendance.view_all']);
    expect(fn () => app(ManageLeave::class)->decide($viewer, $request, 'approved', '', 0))->toThrow(HttpException::class);
    $rider->givePermissionTo(['admin.access', 'attendance.view_all', 'leave.approve']);
    expect(fn () => app(ManageLeave::class)->decide($rider, $request, 'approved', '', 0))->toThrow(ValidationException::class);
});

test('admin approves or rejects from the review buttons and viewers without permission are told why', function (): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    $request = submitLeave($rider, $data);
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(['admin.access', 'attendance.view_all']);
    Livewire::actingAs($viewer)->test(LeaveManagement::class, ['leave' => $request->public_id])
        ->assertSee('leave.approve')->assertDontSee("decide('approved')", false);

    Livewire::actingAs($admin)->test(LeaveManagement::class, ['leave' => $request->public_id])
        ->assertSee("decide('approved')", false)->assertSee("decide('rejected')", false)
        ->call('decide', 'rejected')->assertHasErrors('remarks')
        ->set('remarks', 'Peak delivery week')->call('decide', 'rejected')->assertHasNoErrors()
        ->assertRedirect(route('admin.leave.show', $request->public_id));
    expect($request->fresh())->status->toBe('rejected')->reviewer_remarks->toBe('Peak delivery week');

    $other = submitLeave($rider, [...$data, 'reason' => 'Second application']);
    Livewire::actingAs($admin)->test(LeaveManagement::class, ['leave' => $other->public_id])->call('decide', 'approved')->assertHasNoErrors();
    expect($other->fresh()->status)->toBe('approved');
});

test('supporting documents can be previewed inline as well as downloaded', function (): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    Storage::fake('s3');
    config(['filesystems.disks.s3.bucket' => 'private-leave-tests']);
    $this->mock(MalwareScanner::class)->shouldReceive('scan')->once()->andReturnNull();
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    $request = submitLeave($rider, $data, UploadedFile::fake()->createWithContent('letter.pdf', $pdf));
    $this->actingAs($admin)->get(route('leave.document', ['leave' => $request->public_id, 'preview' => 1]))->assertOk()->assertContent($pdf)
        ->assertHeader('Content-Disposition', 'inline; filename="support.pdf"')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $this->actingAs($admin)->get(route('leave.document', $request))->assertHeader('Content-Disposition', 'attachment; filename="support.pdf"');
    Livewire::actingAs($admin)->test(LeaveManagement::class, ['leave' => $request->public_id])
        ->assertSee(route('leave.document', ['leave' => $request->public_id, 'preview' => 1]), false)->assertSee('<iframe', false);
});

test('rejection requires a reason and terminal decisions cannot be repeated or cancelled', function (): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    $request = submitLeave($rider, $data);
    $action = app(ManageLeave::class);
    expect(fn () => $action->decide($admin, $request, 'rejected', '', 0))->toThrow(ValidationException::class);
    $action->decide($admin, $request, 'rejected', 'Staffing conflict', 0);
    expect($request->fresh()->reviewer_remarks)->toBe('Staffing conflict');
    expect(fn () => $action->decide($admin, $request, 'approved', '', 0))->toThrow(ValidationException::class);
    expect(fn () => $action->cancel($rider, $request, 0))->toThrow(ValidationException::class);
});

test('pending cancellation follows the submitted policy and frees the dates', function (): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    $request = submitLeave($rider, $data);
    $action = app(ManageLeave::class);
    $type->forceFill(['allow_pending_cancel' => false])->save();
    $action->cancel($rider, $request, 0);
    expect($request->fresh()->status)->toBe('cancelled');
    expect(submitLeave($rider, $data)->status)->toBe('pending');
});

test('calendar changes or real attendance prevent approval without altering the request', function (string $conflict): void {
    [$rider,$admin,$type,$data,$schedule] = leaveFixture();
    $request = submitLeave($rider, $data);
    if ($conflict === 'calendar') {
        changeLeaveCalendar($rider, $admin, $schedule);
    } else {
        Attendance::factory()->create(['rider_id' => $rider->staff_id, 'schedule_id' => $schedule->id, 'service_date' => $data['start_date'], 'raw_clock_in_at' => $schedule->scheduled_start_at, 'effective_clock_in_at' => $schedule->scheduled_start_at, 'raw_clock_out_at' => $schedule->scheduled_end_at, 'effective_clock_out_at' => $schedule->scheduled_end_at]);
    }
    expect(fn () => app(ManageLeave::class)->decide($admin, $request, 'approved', '', 0))->toThrow(ValidationException::class);
    expect($request->fresh()->status)->toBe('pending');
})->with(['calendar', 'attendance']);

test('an earlier shift still running into the leave is named in the review panel and blocks approval', function (): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    $request = submitLeave($rider, $data);
    $leaveStart = CarbonImmutable::parse($data['start_date'], 'Asia/Kuala_Lumpur');
    Attendance::factory()->create(['rider_id' => $rider->staff_id, 'service_date' => $leaveStart->subDays(2)->toDateString(), 'raw_clock_in_at' => $leaveStart->subDays(2)->setTime(22, 55)->utc(), 'effective_clock_in_at' => $leaveStart->subDays(2)->setTime(22, 55)->utc(), 'effective_clock_out_at' => $leaveStart->setTime(4, 7)->utc()]);
    $shift = 'The '.$leaveStart->subDays(2)->format('d M Y').' shift (clocked in '.$leaveStart->subDays(2)->format('d M').', 10:55 PM, clocked out '.$leaveStart->format('d M').', 4:07 AM) overlaps this leave.';

    Livewire::actingAs($admin)->test(LeaveManagement::class, ['leave' => $request->public_id])
        ->assertSee("This leave can't be approved yet", false)->assertSee($shift)
        ->call('decide', 'approved')->assertHasErrors('decision');
    expect($request->fresh()->status)->toBe('pending');
});

test('an unfinished clock-in on a leave day does not block approval and the day shows as leave', function (): void {
    [$rider,$admin,$type,$data,$schedule] = leaveFixture();
    $request = submitLeave($rider, $data);
    $punch = Attendance::factory()->create(['rider_id' => $rider->staff_id, 'schedule_id' => $schedule->id, 'service_date' => $data['start_date'], 'raw_clock_in_at' => $schedule->scheduled_start_at, 'effective_clock_in_at' => $schedule->scheduled_start_at]);

    Livewire::actingAs($admin)->test(LeaveManagement::class, ['leave' => $request->public_id])
        ->assertSee('unfinished clock-in')->assertDontSee("This leave can't be approved yet", false)
        ->call('decide', 'approved')->assertHasNoErrors();

    expect($request->fresh()->status)->toBe('approved')->and($punch->fresh()->effective_clock_out_at)->toBeNull();
    $register = app(AttendanceRegister::class)->query($admin, ['from' => $data['start_date'], 'until' => $data['end_date']])->first();
    expect($register->result_status)->toBe('leave');
});

test('approved leave hides clock buttons and supersedes an unfinished clock-in on that day', function (): void {
    [$rider,$admin,$type,$data,$schedule] = leaveFixture();
    $this->travelTo(CarbonImmutable::parse($data['start_date'].' 10:00', 'Asia/Kuala_Lumpur'));
    $punch = app(ClockAttendance::class)->punch($rider, 'in', (string) Str::uuid7());
    $request = submitLeave($rider, $data);
    app(ManageLeave::class)->decide($admin, $request, 'approved', 'Sent home sick', 0);

    Livewire::actingAs($rider)->test(RiderAttendance::class)
        ->assertSee('You are on approved leave today')->assertSee('On leave today')
        ->assertDontSee("wire:click=\"clock('out')\"", false)->assertDontSee("wire:click=\"clock('in')\"", false);
    expect(fn () => app(ClockAttendance::class)->punch($rider, 'out', (string) Str::uuid7(), $punch->public_id, 0))->toThrow(ValidationException::class);
    expect($punch->fresh()->effective_clock_out_at)->toBeNull();

    $this->travelTo(CarbonImmutable::parse($data['start_date'].' 10:00', 'Asia/Kuala_Lumpur')->addDay());
    AttendanceSchedule::factory()->create(['rider_id' => $rider->staff_id, 'service_date' => now()->timezone('Asia/Kuala_Lumpur')->toDateString(), 'scheduled_start_at' => now()->subHour(), 'scheduled_end_at' => now()->addHours(8), 'authorized_by' => $admin->id]);
    expect(app(ClockAttendance::class)->punch($rider, 'in', (string) Str::uuid7())->service_date->toDateString())->toBe(now()->timezone('Asia/Kuala_Lumpur')->toDateString());
});

test('a forgotten clock-out from before the leave cannot be clocked out during approved leave', function (): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    $earlier = CarbonImmutable::parse($data['start_date'], 'Asia/Kuala_Lumpur')->subDays(2)->setTime(12, 26);
    $punch = Attendance::factory()->create(['rider_id' => $rider->staff_id, 'service_date' => $earlier->toDateString(), 'raw_clock_in_at' => $earlier->utc(), 'effective_clock_in_at' => $earlier->utc()]);
    app(ManageLeave::class)->decide($admin, submitLeave($rider, $data), 'approved', '', 0);
    $this->travelTo(CarbonImmutable::parse($data['start_date'].' 10:00', 'Asia/Kuala_Lumpur'));

    Livewire::actingAs($rider)->test(RiderAttendance::class)
        ->assertSee('You are on approved leave today')->assertSee('unfinished clock-in from '.$earlier->format('d M Y'))
        ->assertDontSee("wire:click=\"clock('out')\"", false);
    expect(fn () => app(ClockAttendance::class)->punch($rider, 'out', (string) Str::uuid7(), $punch->public_id, 0))->toThrow(ValidationException::class);
});

test('a forgotten clock-out on an earlier day is not treated as overlapping later leave', function (): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    $request = submitLeave($rider, $data);
    $earlier = CarbonImmutable::parse($data['start_date'], 'Asia/Kuala_Lumpur')->subDays(3)->setTime(12, 26);
    Attendance::factory()->create(['rider_id' => $rider->staff_id, 'service_date' => $earlier->toDateString(), 'raw_clock_in_at' => $earlier->utc(), 'effective_clock_in_at' => $earlier->utc()]);

    expect(app(ManageLeave::class)->attendanceConflicts($request))->toBeEmpty()->and(app(ManageLeave::class)->unfinishedAttendance($request))->toBeEmpty();
    app(ManageLeave::class)->decide($admin, $request, 'approved', '', 0);
    expect($request->fresh()->status)->toBe('approved');
});

test('supporting documents are private scanned immutable and ownership protected', function (): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    Storage::fake('s3');
    config(['filesystems.disks.s3.bucket' => 'private-leave-tests']);
    $type->forceFill(['requires_document' => true])->save();
    expect(fn () => app(ManageLeave::class)->preview($rider, $data))->toThrow(ValidationException::class);
    $this->mock(MalwareScanner::class)->shouldReceive('scan')->once()->andReturnNull();
    $request = submitLeave($rider, $data, UploadedFile::fake()->image('medical.png', 200, 200));
    $doc = $request->document;
    Storage::disk('s3')->assertExists($doc->object_key);
    $this->actingAs($rider)->get(route('leave.document', $request))->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Content-Disposition', 'attachment; filename="support.jpg"');
    Storage::disk('s3')->put($doc->object_key, 'tampered');
    $this->get(route('leave.document', $request))->assertStatus(503);
});

test('invalid dates upload formats and unavailable scanner create no applications', function (string $invalid): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    $file = null;
    if ($invalid === 'dates') {
        $data['end_date'] = '2000-01-01';
    }
    if ($invalid === 'file') {
        $file = UploadedFile::fake()->createWithContent('bad.jpg', 'not an image');
    }
    if ($invalid === 'large') {
        $file = UploadedFile::fake()->image('big.jpg')->size(5121);
    }
    if ($invalid === 'scanner') {
        Storage::fake('s3');
        config(['filesystems.disks.s3.bucket' => 'private-leave-tests']);
        $this->mock(MalwareScanner::class)->shouldReceive('scan')->once()->andThrow(new RuntimeException('scanner_unavailable'));
        $file = UploadedFile::fake()->image('medical.png');
    }
    expect(fn () => submitLeave($rider, $data, $file))->toThrow(ValidationException::class);
    expect(LeaveRequest::count())->toBe(0);
})->with(['dates', 'file', 'large', 'scanner']);

test('Admin can configure deactivate and revise leave types but ordinary riders cannot', function (): void {
    [$rider,$admin] = leaveFixture();
    $data = ['name' => 'Organization approved leave', 'max_calendar_days' => 14, 'requires_document' => true, 'allow_pending_cancel' => false, 'is_active' => true];
    expect(fn () => app(ManageLeave::class)->configure($rider, $data))->toThrow(HttpException::class);
    $type = app(ManageLeave::class)->configure($admin, $data);
    $data['is_active'] = false;
    app(ManageLeave::class)->configure($admin, $data, $type->public_id, 0);
    expect($type->fresh()->is_active)->toBeFalse();
    expect(fn () => app(ManageLeave::class)->configure($admin, $data, $type->public_id, 0))->toThrow(ValidationException::class);
});

test('past dates require an explicit configured rule', function (): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    $past = now()->subDay()->timezone(config('dcms.business_timezone'))->toDateString();
    AttendanceSchedule::factory()->create(['rider_id' => $rider->staff_id, 'service_date' => $past, 'scheduled_start_at' => CarbonImmutable::parse($past.' 09:00', 'Asia/Kuala_Lumpur')->utc(), 'scheduled_end_at' => CarbonImmutable::parse($past.' 18:00', 'Asia/Kuala_Lumpur')->utc()]);
    $data['start_date'] = $past;
    $data['end_date'] = $past;
    expect(fn () => app(ManageLeave::class)->preview($rider, $data))->toThrow(ValidationException::class);
    $type->forceFill(['allow_past_dates' => true])->save();
    expect(submitLeave($rider, $data)->total_days)->toBe(1);
});

test('cancellation remains disabled when the submitted policy forbids it', function (): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    $type->forceFill(['allow_pending_cancel' => false])->save();
    $request = submitLeave($rider, $data);
    expect(fn () => app(ManageLeave::class)->cancel($rider, $request, 0))->toThrow(ValidationException::class);
    expect($request->fresh()->status)->toBe('pending');
});

test('valid PDFs are scanned and downloaded privately and unavailable storage preserves the application form', function (): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    Storage::fake('s3');
    config(['filesystems.disks.s3.bucket' => 'private-leave-tests']);
    $this->mock(MalwareScanner::class)->shouldReceive('scan')->once()->andReturnNull();
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    $request = submitLeave($rider, $data, UploadedFile::fake()->createWithContent('letter.pdf', $pdf));
    $this->actingAs($rider)->get(route('leave.document', $request))->assertOk()->assertContent($pdf)->assertHeader('Content-Disposition', 'attachment; filename="support.pdf"');
    app(ManageLeave::class)->cancel($rider, $request, 0);
    config(['filesystems.disks.s3.bucket' => '']);
    expect(fn () => submitLeave($rider, $data, UploadedFile::fake()->image('other.jpg')))->toThrow(ValidationException::class);
    expect(LeaveRequest::count())->toBe(1);
});

test('application reasons and finalized decisions cannot be rewritten through the database', function (): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    $request = submitLeave($rider, $data);
    expect(fn () => DB::transaction(fn () => DB::table('leave_requests')->where('id', $request->id)->update(['reason' => 'tampered'])))->toThrow(QueryException::class);
    app(ManageLeave::class)->decide($admin, $request, 'approved', 'Reviewed independently', 0);
    expect(fn () => DB::transaction(fn () => DB::table('leave_requests')->where('id', $request->id)->update(['reviewer_remarks' => 'tampered'])))->toThrow(QueryException::class);
    expect($request->fresh()->reason)->toBe($data['reason']);
});

test('Livewire review submission and optional date filters preserve the owned application', function (): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    $component = Livewire::actingAs($rider)->test(LeaveRequests::class)->set('type', $type->public_id)->set('start_date', $data['start_date'])->set('end_date', $data['end_date'])->set('reason', $data['reason'])->call('reviewApplication')->assertHasNoErrors()->assertSet('review.total_days', 1)->assertSet('review.type_name', $type->name);
    $component->call('submit')->assertHasNoErrors();
    $request = LeaveRequest::firstOrFail();
    $component->assertRedirect(route('rider.leave.show', $request));
    Livewire::actingAs($rider)->test(LeaveRequests::class)->set('until', $data['end_date'])->call('filterRequests')->assertHasNoErrors()->assertSee($data['reason']);
    Livewire::actingAs($admin)->test(LeaveManagement::class)->set('until', $data['end_date'])->call('filterRequests')->assertHasNoErrors()->assertSee($type->name);
});

test('approved leave appears in attendance and blocks clock in without fabricating punches', function (): void {
    [$rider,$admin,$type,$data,$schedule] = leaveFixture();
    $request = submitLeave($rider, $data);
    app(ManageLeave::class)->decide($admin, $request, 'approved', 'Independent leave approval', 0);
    $this->travelTo(CarbonImmutable::parse($data['start_date'].' 10:00', 'Asia/Kuala_Lumpur'));

    $register = app(AttendanceRegister::class)->query($admin, ['from' => $data['start_date'], 'until' => $data['end_date']])->first();
    expect($register->result_status)->toBe('leave')->and($schedule->fresh()->day_type)->toBe('scheduled')->and(Attendance::count())->toBe(0);
    expect(fn () => app(ClockAttendance::class)->punch($rider, 'in', (string) Str::uuid7()))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('attendances', 0);
    $rider->givePermissionTo('attendance.correct');
    expect(fn () => changeLeaveCalendar($rider, $admin, $schedule))->toThrow(ValidationException::class);
});

test('pending rejected and cancelled leave do not mark attendance as approved leave', function (string $state): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    $request = submitLeave($rider, $data);
    if ($state === 'rejected') {
        app(ManageLeave::class)->decide($admin, $request, 'rejected', 'Independent rejection reason', 0);
    } elseif ($state === 'cancelled') {
        app(ManageLeave::class)->cancel($rider, $request, 0);
    }
    $row = app(AttendanceRegister::class)->query($admin, ['from' => $data['start_date'], 'until' => $data['end_date']])->first();
    expect($row->result_status)->not->toBe('leave');
})->with(['pending', 'rejected', 'cancelled']);

test('deactivated leave types retain submitted policy and existing requests can be reviewed', function (): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    $request = submitLeave($rider, $data);
    $snapshot = $request->fresh()->type_snapshot;
    app(ManageLeave::class)->configure($admin, ['name' => $type->name, 'max_calendar_days' => 1, 'requires_document' => false, 'allow_pending_cancel' => false, 'is_active' => false], $type->public_id, $type->lock_version);

    expect(fn () => app(ManageLeave::class)->preview($rider, $data))->toThrow(ValidationException::class);
    app(ManageLeave::class)->decide($admin, $request, 'approved', 'Review using submitted policy', 0);
    expect($request->fresh()->type_snapshot)->toBe($snapshot)->and($request->fresh()->status)->toBe('approved');
    $this->assertDatabaseCount('leave_requests', 1);
});

test('required application fields are enforced without partial records', function (string $field): void {
    [$rider,$admin,$type,$data] = leaveFixture();
    unset($data[$field]);
    try {
        app(ManageLeave::class)->preview($rider, $data);
        $this->fail('A required field was omitted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    $this->assertDatabaseCount('leave_requests', 0);
})->with(['type', 'start_date', 'end_date', 'reason']);
