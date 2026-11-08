<?php

use App\Livewire\Admin\Home;
use App\Models\AssignmentBatch;
use App\Models\DebtAccount;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\User;
use App\Services\AttentionQueue;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
    $this->travelTo('2026-10-31 04:00:00');
});

function attentionTask(AssignmentBatch $batch, array $attributes = []): Task
{
    return Task::factory()->create(['assignment_batch_id' => $batch->id, 'debt_account_id' => DebtAccount::factory()->create()->id, ...$attributes]);
}

test('task attention counts match their linked task filters', function (): void {
    $user = staffManager();
    $batch = AssignmentBatch::factory()->create(['due_date' => '2026-12-31']);
    attentionTask($batch, ['work_status' => 'submitted', 'review_status' => 'pending', 'submitted_at' => '2026-10-28 04:00:00']);
    attentionTask($batch, ['due_at' => '2026-10-30 00:00:00']);
    attentionTask($batch, ['work_status' => 'in_progress', 'review_status' => 'returned']);
    $allocated = attentionTask($batch);
    TaskAllocation::factory()->create(['task_id' => $allocated->id, 'collector_id' => Staff::factory()->create()->id]);
    attentionTask($batch, ['work_status' => 'completed', 'review_status' => 'approved', 'due_at' => '2026-10-29 00:00:00']);

    $items = collect(app(AttentionQueue::class)->forUser($user))->keyBy('key');

    expect($items['pending_verification'])->toMatchArray(['count' => 1, 'detail' => 'Oldest waiting 3 days'])
        ->and($items['overdue']['count'])->toBe(1)->and($items['overdue']['href'])->toBe(route('admin.tasks.index', ['status' => 'overdue']))
        ->and($items['unassigned']['count'])->toBe(3)
        ->and($items['returned']['count'])->toBe(1);
    Livewire::actingAs($user)->test(Home::class)->assertSee('Needs attention')->assertSee('Oldest waiting 3 days')
        ->assertDontSee('Latest customers')->assertDontSee('Activity feed');
});

test('tasks without their own due time become overdue after their batch due date ends locally', function (): void {
    $user = staffManager();
    attentionTask(AssignmentBatch::factory()->create(['start_date' => '2026-10-01', 'due_date' => '2026-10-30']));
    attentionTask(AssignmentBatch::factory()->create(['start_date' => '2026-10-01', 'due_date' => '2026-10-31']));

    $items = collect(app(AttentionQueue::class)->forUser($user))->keyBy('key');

    expect($items['overdue']['count'])->toBe(1);
});

test('attention items with work waiting come first and missed deadlines lead and stand out', function (): void {
    $user = staffManager();
    $batch = AssignmentBatch::factory()->create(['due_date' => '2026-12-31']);
    attentionTask($batch, ['work_status' => 'in_progress', 'review_status' => 'returned']);
    attentionTask($batch, ['due_at' => '2026-10-30 00:00:00']);

    $items = collect(app(AttentionQueue::class)->forUser($user));

    expect($items->take(3)->pluck('key')->all())->toBe(['overdue', 'unassigned', 'returned'])
        ->and($items->skip(3)->sum('count'))->toBe(0)
        ->and($items->firstWhere('key', 'overdue')['urgent'])->toBeTrue();
    Livewire::actingAs($user)->test(Home::class)->assertSeeHtml('border-red-200 bg-red-50');
});

test('an overdue card with nothing waiting does not stand out', function (): void {
    $user = staffManager();

    $items = collect(app(AttentionQueue::class)->forUser($user));

    expect($items->sum('count'))->toBe(0);
    Livewire::actingAs($user)->test(Home::class)->assertSee('All clear')->assertDontSeeHtml('border-red-200 bg-red-50');
});

test('admin access alone shows no attention items', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('admin.access');

    expect(app(AttentionQueue::class)->forUser($user))->toBe([]);
    Livewire::actingAs($user)->test(Home::class)->assertDontSee('Needs attention');
});
