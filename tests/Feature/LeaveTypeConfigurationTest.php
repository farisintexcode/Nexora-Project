<?php

use App\Actions\Attendance\ManageLeave;
use App\Livewire\Admin\Attendance\LeaveTypes;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

function leavePolicyAdministrator(): User
{
    $actor = User::factory()->create();
    $actor->assignRole('Administrative');

    return $actor;
}

function leavePolicyInput(array $overrides = []): array
{
    return [...['name' => 'Organization Annual Leave', 'max_calendar_days' => 10, 'requires_document' => false, 'allow_past_dates' => false, 'allow_pending_cancel' => true, 'is_active' => true], ...$overrides];
}

test('authorized administrator creates edits deactivates and reactivates a leave type', function (): void {
    $admin = leavePolicyAdministrator();
    $component = Livewire::actingAs($admin)->test(LeaveTypes::class)->assertSee('No leave types configured')
        ->set('name', 'Organization Annual Leave')->set('max_calendar_days', 10)->set('allow_pending_cancel', true)
        ->call('saveType')->assertHasNoErrors();
    $type = LeaveType::query()->sole();
    expect($type->configured_by)->toBe($admin->id)->and($type->is_active)->toBeTrue();

    $component->call('editType', $type->public_id)->set('max_calendar_days', 5)->call('saveType')->assertHasNoErrors()
        ->call('setActive', $type->public_id, false, 1)->assertHasNoErrors();
    expect($type->fresh()->is_active)->toBeFalse()->and($type->fresh()->max_calendar_days)->toBe(5);
    $component->call('setActive', $type->public_id, true, 2)->assertHasNoErrors();
    expect($type->fresh()->is_active)->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'leave.type_configured', 'entity_id' => $type->public_id]);
});

test('stale availability action cannot overwrite a newer policy edit', function (): void {
    $admin = leavePolicyAdministrator();
    $type = app(ManageLeave::class)->configure($admin, leavePolicyInput());
    app(ManageLeave::class)->configure($admin, leavePolicyInput(['max_calendar_days' => 4]), $type->public_id, 0);

    Livewire::actingAs($admin)->test(LeaveTypes::class)->call('setActive', $type->public_id, false, 0)->assertHasErrors('type');

    expect($type->fresh()->is_active)->toBeTrue()->and($type->fresh()->max_calendar_days)->toBe(4);
});

test('configuration rejects invalid names spans duplicate names and missing rules', function (array $changes, string $field): void {
    $admin = leavePolicyAdministrator();
    try {
        app(ManageLeave::class)->configure($admin, leavePolicyInput($changes));
        $this->fail('Invalid policy must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    $this->assertDatabaseCount('leave_types', 0);
})->with([
    'blank name' => [['name' => '   '], 'name'],
    'zero span' => [['max_calendar_days' => 0], 'max_calendar_days'],
    'excessive span' => [['max_calendar_days' => 94], 'max_calendar_days'],
    'invalid document policy' => [['requires_document' => 'maybe'], 'requires_document'],
]);

test('leave names are unique irrespective of case and surrounding whitespace', function (): void {
    $admin = leavePolicyAdministrator();
    app(ManageLeave::class)->configure($admin, leavePolicyInput());
    expect(fn () => app(ManageLeave::class)->configure($admin, leavePolicyInput(['name' => ' organization annual leave '])))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('leave_types', 1);
});

test('Rider and Administrative without explicit configuration authority cannot configure leave', function (string $role): void {
    $actor = User::factory()->for(Staff::factory())->create();
    $actor->assignRole($role);
    $this->actingAs($actor)->get(route('admin.leave.types'))->assertForbidden();
    Livewire::actingAs($actor)->test(LeaveTypes::class)->assertForbidden();
    expect(fn () => app(ManageLeave::class)->configure($actor, leavePolicyInput()))->toThrow(HttpException::class);
    $this->assertDatabaseCount('leave_types', 0);
})->with(['Rider', 'Administrative']);

test('revoking configuration permission blocks an already opened configuration component', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['admin.access', 'attendance.view_all', 'attendance.configure']);
    $component = Livewire::actingAs($actor)->test(LeaveTypes::class);
    $actor->revokePermissionTo('attendance.configure');

    $component->call('saveType')->assertForbidden();
    $this->assertDatabaseCount('leave_types', 0);
});

test('empty leave application gives actionable guidance without an unusable form', function (): void {
    $rider = User::factory()->for(Staff::factory())->create();
    $rider->assignRole('Rider');
    $this->actingAs($rider)->get(route('rider.leave.apply'))->assertOk()->assertSee('Leave applications are not available yet')->assertDontSee('Review Application');

    $admin = leavePolicyAdministrator();
    app(ManageLeave::class)->configure($admin, leavePolicyInput());
    app(ManageLeave::class)->configure($admin, leavePolicyInput(['name' => 'Inactive Leave', 'is_active' => false]));
    $this->actingAs($rider)->get(route('rider.leave.apply'))->assertOk()->assertSee('Organization Annual Leave')->assertDontSee('Inactive Leave')->assertSee('Review Application');
});

test('local sample command is additive opt in and preserves edited policy rules', function (): void {
    $admin = leavePolicyAdministrator();
    $this->artisan('dcms:seed-leave-samples', ['actor' => $admin->email, '--activate' => true])->assertSuccessful();
    $this->assertDatabaseCount('leave_types', 5);
    $type = LeaveType::query()->where('name', 'Sample Annual Leave')->sole();
    app(ManageLeave::class)->configure($admin, leavePolicyInput(['name' => $type->name, 'max_calendar_days' => 3, 'is_active' => false]), $type->public_id, 0);
    $this->artisan('dcms:seed-leave-samples', ['actor' => $admin->email, '--activate' => true])->assertSuccessful();
    $this->assertDatabaseCount('leave_types', 5);
    expect($type->fresh()->max_calendar_days)->toBe(3)->and($type->fresh()->is_active)->toBeFalse();
});

test('sample command refuses unauthorized actors and production', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('Administrative');
    $this->artisan('dcms:seed-leave-samples', ['actor' => $actor->email])->assertFailed();
    app()->detectEnvironment(fn (): string => 'production');
    $this->artisan('dcms:seed-leave-samples', ['actor' => $actor->email])->assertFailed();
    $this->assertDatabaseCount('leave_types', 0);
});

test('an unused leave type can be deleted but one with applications is kept for history', function (): void {
    $admin = leavePolicyAdministrator();
    $unused = app(ManageLeave::class)->configure($admin, leavePolicyInput(['name' => 'Unused leave']));
    $used = app(ManageLeave::class)->configure($admin, leavePolicyInput(['name' => 'Used leave']));
    LeaveRequest::factory()->create(['leave_type_id' => $used->id]);

    Livewire::actingAs($admin)->test(LeaveTypes::class)
        ->assertDontSee('>Activate<', false)->assertDontSee('>Deactivate<', false)
        ->call('deleteType', $unused->public_id)->assertHasNoErrors()
        ->call('deleteType', $used->public_id)->assertHasErrors('delete');

    expect(LeaveType::query()->whereKey($unused->id)->exists())->toBeFalse()
        ->and(LeaveType::query()->whereKey($used->id)->exists())->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'leave.type_deleted', 'entity_id' => $unused->public_id]);
});
