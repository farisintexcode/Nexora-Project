<?php

use App\Actions\Staff\SaveStaff;
use App\Livewire\Admin\Staff\Editor;
use App\Livewire\Admin\Staff\Index;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

test('staff creation preserves staff number encrypts private contacts and never grants an account', function (): void {
    $this->travelTo('2026-01-15 09:00:00');
    $actor = staffManager();
    $staff = app(SaveStaff::class)->handle($actor, staffInput(['position_code' => '99', 'roles' => ['Admin'], 'permissions' => ['roles.manage']]));

    expect($staff->staff_no)->toBe('ST260101')->and($staff->position_code)->toBe('99')->and($staff->user)->toBeNull()
        ->and($staff->phone_ciphertext)->toBe('+60123456789')->and($staff->getRawOriginal('phone_ciphertext'))->not->toContain('+60123456789');
    $this->assertDatabaseCount('users', 1);
    expect(AuditLog::query()->where('module', 'M10')->first()->toJson())->not->toContain('+60123456789', '10 Synthetic Test Road', 'nadia@example.test');
    Livewire::actingAs($actor)->test(Index::class)->assertSee('ST260101')->assertSee('Nadia Test')->assertDontSee('+60123456789');
});

test('staff validation rejects invalid fields without partial persistence', function (array $invalid, string $field): void {
    $audits = AuditLog::query()->count();
    $actor = staffManager();

    Livewire::actingAs($actor)->test(Editor::class)->set('data', staffInput($invalid))->call('save')->assertHasErrors('data.'.$field);

    $this->assertDatabaseCount('staff', 0);
    $this->assertDatabaseCount('audit_logs', $audits);
})->with([
    [['first_name' => []], 'first_name'], [['phone' => ''], 'phone'], [['email' => 'invalid'], 'email'],
    [['system_role' => 'Unknown'], 'system_role'], [['gender_code' => '99'], 'gender_code'],
    [['appointment_date' => '2026-02-30'], 'appointment_date'], [['phone' => 'letters'], 'phone'], [['is_rider' => 'wrong'], 'is_rider'],
]);

test('staff numbers are generated per year and continue across months', function (): void {
    $actor = staffManager();
    $save = fn (array $overrides = []): Staff => app(SaveStaff::class)->handle($actor, staffInput(['email' => fake()->unique()->safeEmail(), ...$overrides]));

    $this->travelTo('2026-01-10 09:00:00');
    expect($save()->staff_no)->toBe('ST260101')
        ->and($save(['staff_no' => 'MANUAL-1'])->staff_no)->toBe('ST260102');

    $this->travelTo('2026-04-03 09:00:00');
    expect($save()->staff_no)->toBe('ST260403');

    $this->travelTo('2027-01-05 09:00:00');
    expect($save()->staff_no)->toBe('ST270101');
    Livewire::actingAs($actor)->test(Editor::class)->assertSet('data.staff_no', 'ST270102');
});

test('editing staff keeps the existing staff number', function (): void {
    $this->travelTo('2026-01-10 09:00:00');
    $actor = staffManager();
    $staff = app(SaveStaff::class)->handle($actor, staffInput());

    $updated = app(SaveStaff::class)->handle($actor, staffInput(['staff_no' => 'CHANGED-1', 'last_name' => 'Updated']), $staff, $staff->revision());

    expect($updated->staff_no)->toBe('ST260101')->and($updated->last_name)->toBe('Updated');
});

test('work phone is required for new staff but stays optional when editing', function (): void {
    $actor = staffManager();

    expect(fn () => app(SaveStaff::class)->handle($actor, staffInput(['phone' => ''])))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('staff', 0);

    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    $updated = app(SaveStaff::class)->handle($actor, staffInput(['phone' => '']), $staff, $staff->revision());

    expect($updated->phone_ciphertext)->toBeNull();
});

test('stale edits including same-second reverted values cannot overwrite staff history', function (): void {
    $actor = staffManager();
    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    $original = $staff->revision();
    $updated = app(SaveStaff::class)->handle($actor, staffInput(['first_name' => 'Changed']), $staff, $original);
    $reverted = app(SaveStaff::class)->handle($actor, staffInput(), $updated, $updated->revision());

    expect($reverted->revision())->not->toBe($original);
    expect(fn () => app(SaveStaff::class)->handle($actor, staffInput(['first_name' => 'Stale overwrite']), $staff, $original))->toThrow(ValidationException::class);
    expect($staff->fresh()->first_name)->toBe('Nadia');
});

test('deactivation requires a reason preserves account permissions and blocks an existing session', function (): void {
    $actor = staffManager();
    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    $rider = User::factory()->for($staff)->create();
    $rider->assignRole('Rider');
    $before = $rider->getAllPermissions()->pluck('name')->sort()->all();

    expect(fn () => app(SaveStaff::class)->handle($actor, staffInput(['is_active' => false]), $staff, $staff->revision()))->toThrow(ValidationException::class);
    app(SaveStaff::class)->handle($actor, staffInput(['is_active' => false, 'reason' => 'Employment paused']), $staff, $staff->revision());

    expect($rider->fresh()->isActive())->toBeFalse()->and($rider->fresh()->getAllPermissions()->pluck('name')->sort()->all())->toBe($before);
    $this->actingAs($rider)->get(route('rider.home'))->assertRedirect(route('login'));
    $this->assertGuest();
    $this->assertDatabaseHas('audit_logs', ['action' => 'staff_updated', 'reason' => 'Employment paused']);
});

test('removing rider eligibility is independent of its role and stops rider access', function (): void {
    $actor = staffManager();
    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    $rider = User::factory()->for($staff)->create();
    $rider->assignRole('Rider');

    app(SaveStaff::class)->handle($actor, staffInput(['is_rider' => false, 'reason' => 'Moved to office work']), $staff, $staff->revision());

    expect($rider->fresh()->hasRole('Rider'))->toBeTrue()->and($rider->fresh()->canUseRiderInterface())->toBeFalse();
    $this->actingAs($rider)->get(route('rider.home'))->assertForbidden();
});

test('staff cannot reduce their own availability', function (): void {
    $actor = staffManager();
    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    $actor->staff_id = $staff->id;
    $actor->save();

    expect(fn () => app(SaveStaff::class)->handle($actor, staffInput(['is_active' => false, 'reason' => 'Self disable']), $staff, $staff->revision()))->toThrow(ValidationException::class);
    expect($staff->fresh()->is_active)->toBeTrue();
});

test('outstanding current allocations block availability changes while terminal and released work do not', function (): void {
    $actor = staffManager();
    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    // Exercise outstanding-work rules against the canonical M05 tables.
    $taskId = Task::factory()->create()->id;
    TaskAllocation::factory()->create(['task_id' => $taskId, 'collector_id' => $staff->id]);

    expect(fn () => app(SaveStaff::class)->handle($actor, staffInput(['is_active' => false, 'reason' => 'Employment ended']), $staff, $staff->revision()))->toThrow(ValidationException::class);
    DB::table('task_allocations')->where('collector_id', $staff->id)->update(['released_at' => now()->format('Y-m-d H:i:s.uP')]);
    $released = app(SaveStaff::class)->handle($actor, staffInput(['is_active' => false, 'reason' => 'Work was released']), $staff, $staff->revision());
    expect($released->is_active)->toBeFalse();
    $staff = app(SaveStaff::class)->handle($actor, staffInput(), $released, $released->revision());
    DB::table('task_allocations')->where('collector_id', $staff->id)->update(['released_at' => null]);
    DB::table('tasks')->where('id', $taskId)->update(['work_status' => 'completed', 'review_status' => 'approved']);
    $saved = app(SaveStaff::class)->handle($actor, staffInput(['is_active' => false, 'reason' => 'Employment ended']), $staff, $staff->revision());
    expect($saved->is_active)->toBeFalse();
});

test('personnel routes and crafted actions reject a Rider and a user without management permission', function (): void {
    $staff = Staff::factory()->create();
    $rider = User::factory()->for($staff)->create();
    $rider->assignRole('Rider');
    $this->actingAs($rider)->get(route('admin.staff.index'))->assertForbidden();
    $this->actingAs($rider)->get(route('admin.staff.show', ['staff' => $staff->public_id]))->assertForbidden();
    $user = User::factory()->create();
    $user->givePermissionTo('admin.access');
    expect(fn () => app(SaveStaff::class)->handle($user, staffInput()))->toThrow(AuthorizationException::class);
});

test('directory defaults to Riders and safely filters office staff dates and literal searches', function (): void {
    $actor = staffManager();
    app(SaveStaff::class)->handle($actor, staffInput());
    app(SaveStaff::class)->handle($actor, staffInput(['staff_no' => '000124', 'first_name' => 'Office', 'last_name' => 'Only', 'is_rider' => false]));

    Livewire::actingAs($actor)->test(Index::class)->assertSee('Nadia Test')->assertDontSee('Office Only')->set('kind', 'all')->assertSee('Office Only')
        ->set('query', '%')->assertDontSee('Nadia Test')->set('query', '')->set('from', 'invalid-date')->assertOk()->assertDontSee('Nadia Test')
        ->set('from', '')->set('sort', 'invalid;delete')->assertOk()->assertSee('Nadia Test');
});

test('permission removal blocks a previously opened staff form and malformed references return 404', function (): void {
    $actor = staffManager();
    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    $component = Livewire::actingAs($actor)->test(Editor::class, ['staff' => $staff]);
    $actor->syncRoles([]);
    $actor->givePermissionTo('admin.access');

    $component->set('data.first_name', 'Unauthorized edit')->assertForbidden();
    expect(fn () => app(SaveStaff::class)->handle($actor, staffInput(), $staff, $staff->revision()))->toThrow(AuthorizationException::class);
    expect($staff->fresh()->first_name)->toBe('Nadia');
    $this->actingAs($actor)->get('/admin/staff/not-a-uuid')->assertNotFound();
});

test('inactive reference values remain on historical staff but cannot be assigned to new records', function (): void {
    $actor = staffManager();
    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    DB::table('reference_values')->where('category', 'position')->where('code', '01')->update(['is_active' => false]);

    $updated = app(SaveStaff::class)->handle($actor, staffInput(['last_name' => 'Updated']), $staff, $staff->revision());
    expect($updated->position_code)->toBe('01');
    expect(fn () => app(SaveStaff::class)->handle($actor, staffInput(['staff_no' => '000125'])))->toThrow(ValidationException::class);
});

test('staff system role starts blank and requires explicit selection', function (): void {
    $actor = staffManager();
    Livewire::actingAs($actor)->test(Editor::class)
        ->assertSet('data.system_role', '')
        ->assertSet('data.is_rider', false)
        ->assertSee('Select role')
        ->set('data', staffInput(['system_role' => '', 'create_account' => false]))
        ->call('save')->assertHasErrors('data.system_role');
    $this->assertDatabaseCount('staff', 0);
});

test('staff system role preserves editing selection and determines Rider eligibility', function (string $role): void {
    $actor = User::factory()->create();
    $actor->assignRole('System Admin');
    $staff = app(SaveStaff::class)->handle($actor, staffInput(['system_role' => $role, 'create_account' => false]));
    expect($staff->is_rider)->toBe($role === 'Rider');
    $account = User::factory()->for($staff)->create();
    $account->assignRole($role);
    Livewire::actingAs($actor)->test(Editor::class, ['staff' => $staff])
        ->assertSet('data.system_role', $role)
        ->call('save')->assertHasNoErrors();
    expect($account->fresh()->getRoleNames()->all())->toBe([$role]);
    expect($staff->fresh()->is_rider)->toBe($role === 'Rider');
})->with(['System Admin', 'Administrative', 'Rider']);

test('explicit non Rider role removes legacy Rider eligibility when editing', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('System Admin');
    $staff = Staff::factory()->create(['is_rider' => true]);
    $updated = app(SaveStaff::class)->handle($actor, staffInput(['system_role' => 'Administrative', 'create_account' => false, 'reason' => 'Selected office role']), $staff, $staff->revision());
    expect($updated->is_rider)->toBeFalse();
});
