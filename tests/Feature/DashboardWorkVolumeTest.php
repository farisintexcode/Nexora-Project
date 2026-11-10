<?php

use App\Livewire\Admin\Home;
use App\Models\AssignmentBatch;
use App\Models\DebtAccount;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\WorkVolume;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
    $this->travelTo('2026-10-31 04:00:00');
});

function volumeTask(AssignmentBatch $batch, array $attributes = []): Task
{
    return Task::factory()->create(['assignment_batch_id' => $batch->id, 'debt_account_id' => DebtAccount::factory()->create()->id, ...$attributes]);
}

test('daily volume counts tasks created and approved per local day', function (): void {
    $user = staffManager();
    $batch = AssignmentBatch::factory()->create();
    $localMidnight = now()->timezone(config('dcms.business_timezone'))->startOfDay()->utc();
    $older = now()->subDays(10);
    $approved = ['created_at' => $older, 'work_status' => 'completed', 'review_status' => 'approved'];
    volumeTask($batch);
    volumeTask($batch, ['created_at' => $localMidnight->copy()->subSecond()]);
    volumeTask($batch, ['created_at' => now()->subDays(2)]);
    volumeTask($batch, ['created_at' => $older]);
    volumeTask($batch, [...$approved, 'completed_at' => now()]);
    volumeTask($batch, [...$approved, 'completed_at' => $localMidnight->copy()->subSecond()]);
    volumeTask($batch, ['created_at' => $older, 'work_status' => 'submitted', 'review_status' => 'pending', 'submitted_at' => now()]);

    $volume = app(WorkVolume::class)->forUser($user, 'daily', 7);

    expect($volume['buckets'])->toHaveCount(7)
        ->and($volume['buckets']->pluck('created')->all())->toBe([0, 0, 0, 0, 1, 1, 1])
        ->and($volume['buckets']->pluck('approved')->all())->toBe([0, 0, 0, 0, 0, 1, 1])
        ->and($volume['buckets']->last()['date'])->toBe('31 Oct 2026')
        ->and($volume['created'])->toBe(3)->and($volume['approved'])->toBe(2);
});

test('monthly volume groups by local month', function (): void {
    $user = staffManager();
    $batch = AssignmentBatch::factory()->create();
    volumeTask($batch, ['created_at' => '2026-08-15 04:00:00', 'work_status' => 'completed', 'review_status' => 'approved', 'completed_at' => '2026-09-02 04:00:00']);
    volumeTask($batch, ['created_at' => '2026-07-20 04:00:00']);
    volumeTask($batch);

    $volume = app(WorkVolume::class)->forUser($user, 'monthly', 3);

    expect($volume['buckets']->pluck('label')->all())->toBe(['Aug', 'Sep', 'Oct'])
        ->and($volume['buckets']->pluck('created')->all())->toBe([1, 0, 1])
        ->and($volume['buckets']->pluck('approved')->all())->toBe([0, 1, 0]);
});

test('dashboard filters switch the period and fall back to a supported range', function (): void {
    $user = staffManager();

    Livewire::actingAs($user)->test(Home::class)
        ->assertSee('Tasks created and approved')->assertViewHas('volumeRange', 7)
        ->set('period', 'monthly')->assertSet('range', '12')->assertViewHas('volumeRange', 12)
        ->set('range', '99')->assertViewHas('volumeRange', 3);
});

test('admin access without task permission does not show work volume', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('admin.access', 'assignments.view');

    expect(app(WorkVolume::class)->forUser($user, 'daily', 7))->toBeNull();
    Livewire::actingAs($user)->test(Home::class)->assertDontSee('Tasks created and approved');
});
