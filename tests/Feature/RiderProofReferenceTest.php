<?php

use App\Actions\Tasks\RiderWork;
use App\Models\Document;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

/** @return array{0: User, 1: Task} */
function proofReferenceFixture(): array
{
    $task = Task::factory()->create();
    $actor = User::factory()->for(Staff::factory()->create())->create();
    $actor->assignRole('Rider');
    TaskAllocation::factory()->create(['task_id' => $task->id, 'collector_id' => $actor->staff_id]);
    app(RiderWork::class)->start($actor, $task->refresh(), $task->lock_version);
    app(RiderWork::class)->visit($actor, $task->refresh(), $task->lock_version, ['purpose' => 'site_visit', 'business_applicable' => false, 'signboard_available' => null, 'occurred_at' => '', 'site_address' => 'Synthetic field address', 'availability' => 'available', 'visit_status' => 'operating', 'contact_result' => 'customer_contacted', 'outcome' => 'successful_visit', 'visit_remarks' => 'Synthetic field observation'], (string) Str::uuid7());

    return [$actor, $task->refresh()];
}

function proofReferenceUpload(User $actor, Task $task): Document
{
    return Document::factory()->create(['owner_type' => 'task', 'owner_id' => $task->id, 'activity_id' => $task->activities()->latest('id')->firstOrFail()->id, 'uploaded_by' => $actor->id, 'document_type_code' => 'proof_image', 'image_category_code' => 'premises', 'scan_status' => 'clean']);
}

test('proof references are visible stable and different for different uploads', function (): void {
    [$actor, $task] = proofReferenceFixture();
    $first = proofReferenceUpload($actor, $task);
    $second = proofReferenceUpload($actor, $task);
    expect($first->proofReference())->toStartWith('IMG-')->not->toBe($second->proofReference());
    $this->actingAs($actor)->get(route('rider.tasks.show', ['task' => $task, 'section' => 'proof']))->assertOk()->assertSee($first->proofReference());
});
