<?php

use App\Models\DebtAccount;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\User;
use App\Notifications\TaskReviewed;
use App\Services\Tasks\RiderAssignments;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

function riderPresentationFixture(): array
{
    $task = Task::factory()->create();
    $rider = User::factory()->for(Staff::factory()->create())->create();
    $rider->assignRole('Rider');
    TaskAllocation::factory()->create(['task_id' => $task->id, 'collector_id' => $rider->staff_id]);

    return [$rider, $task];
}

test('Rider assignment and notification routes require the correct active interface', function (): void {
    $this->get(route('rider.assignments.index'))->assertRedirect(route('rider.login'));
    $this->get(route('rider.notifications'))->assertRedirect(route('rider.login'));
    $this->actingAs(staffManager())->get(route('rider.assignments.index'))->assertForbidden();
    [$rider] = riderPresentationFixture();
    $rider->syncRoles([]);
    $rider->givePermissionTo('rider.access');
    $this->actingAs($rider)->get(route('rider.assignments.index'))->assertForbidden();
});

test('Rider assignment summaries count only currently allocated tasks and details exclude colleagues work', function (): void {
    [$rider,$task] = riderPresentationFixture();
    $other = Task::factory()->create(['assignment_batch_id' => $task->assignment_batch_id, 'debt_account_id' => DebtAccount::factory()->create()->id]);
    $summary = app(RiderAssignments::class)->batches($rider)->sole();
    expect((int) $summary->task_count)->toBe(1)->and((int) $summary->pending_count)->toBe(1)->and((int) $summary->completed_count)->toBe(0);
    $this->actingAs($rider)->get(route('rider.assignments.index'))->assertOk()->assertSee($task->batch->name);
    $this->get(route('rider.assignments.show', $task->batch->public_id))->assertOk()->assertSee($task->customer->display_name_ciphertext)->assertDontSee($other->customer->display_name_ciphertext);
});

test('Rider screens show the assignment code with the assignment name', function (): void {
    [$rider,$task] = riderPresentationFixture();
    $code = $task->batch->refresh()->assignment_code;
    $label = $code.' - '.$task->batch->name;
    $this->actingAs($rider)->get(route('rider.home'))->assertOk()->assertSee($label);
    $this->get(route('rider.tasks.index'))->assertOk()->assertSee($label);
    $this->get(route('rider.tasks.show', $task))->assertOk()->assertSee($label);
    $this->get(route('rider.tasks.manage'))->assertOk()->assertSee($label);
    $this->get(route('rider.assignments.index'))->assertOk()->assertSee($code);
    $this->get(route('rider.assignments.show', $task->batch->public_id))->assertOk()->assertSee($code);
});

test('Rider cannot open a foreign or unallocated assignment', function (): void {
    [$rider,$task] = riderPresentationFixture();
    $foreign = Task::factory()->create();
    $this->actingAs($rider)->get(route('rider.assignments.show', $foreign->batch->public_id))->assertNotFound();
});

test('read-only assignment navigation does not change tasks allocations or submissions', function (): void {
    [$rider,$task] = riderPresentationFixture();
    $before = $task->refresh()->getRawOriginal();
    $allocation = $task->currentAllocation->getRawOriginal();
    $this->actingAs($rider)->get(route('rider.assignments.index'))->assertOk();
    $this->get(route('rider.assignments.show', $task->batch->public_id))->assertOk();
    expect($task->refresh()->getRawOriginal())->toBe($before)->and($task->currentAllocation->getRawOriginal())->toBe($allocation);
});

test('Rider notifications are owned scoped and remain unread when merely displayed', function (): void {
    [$rider,$task] = riderPresentationFixture();
    $notice = $rider->notifications()->create(['id' => (string) Str::uuid(), 'type' => TaskReviewed::class, 'data' => ['task' => $task->public_id, 'decision' => 'approved', 'message' => 'Your task was verified and completed.']]);
    $other = User::factory()->create();
    $other->notifications()->create(['id' => (string) Str::uuid(), 'type' => TaskReviewed::class, 'data' => ['task' => $task->public_id, 'message' => 'FOREIGN-NOTICE-CONTENT']]);
    $this->actingAs($rider)->get(route('rider.notifications'))->assertOk()->assertSee('Your task was verified and completed.')->assertDontSee('FOREIGN-NOTICE-CONTENT')->assertSee(route('rider.tasks.show', $task));
    expect($notice->fresh()->read_at)->toBeNull();
    $task->currentAllocation->forceFill(['released_at' => now()])->save();
    $this->get(route('rider.notifications'))->assertOk()->assertSee('no longer in your current allocation')->assertDontSee(route('rider.tasks.show', $task));
});

test('Rider shell has exactly the four requested primary destinations and profile in the header', function (): void {
    [$rider] = riderPresentationFixture();
    $this->actingAs($rider)->get(route('rider.home'))->assertOk()->assertSee('aria-label="Profile"', false)->assertSee('Assignment overview')->assertSee('My Task')->assertSee(route('rider.assignments.index'));
    $this->get(route('account.settings'))->assertOk()->assertSee('Account Settings');
});
