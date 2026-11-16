<?php

use App\Actions\Tasks\RiderBatchUpdate;
use App\Actions\Tasks\RiderWork;
use App\Livewire\Rider\Tasks\Manage;
use App\Models\CollectionActivity;
use App\Models\Document;
use App\Models\FollowUp;
use App\Models\PropertyInspection;
use App\Models\ReferenceValue;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\User;
use App\Services\Tasks\CompletionChecklist;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

function batchFlowFixture(int $count = 2): array
{
    $first = Task::factory()->create();
    $actor = User::factory()->for(Staff::factory()->create())->create();
    $actor->assignRole('Rider');
    $tasks = collect([$first]);
    for ($index = 1; $index < $count; $index++) {
        $tasks->push(Task::factory()->create(['assignment_batch_id' => $first->assignment_batch_id, 'debt_account_id' => $first->debt_account_id, 'customer_id' => $first->customer_id, 'type_code' => $first->type_code]));
    }
    foreach ($tasks as $task) {
        TaskAllocation::factory()->create(['task_id' => $task->id, 'collector_id' => $actor->staff_id]);
        app(RiderWork::class)->start($actor, $task->refresh(), $task->lock_version);
        app(RiderWork::class)->visit($actor, $task->refresh(), $task->lock_version, ['purpose' => 'site_visit', 'business_applicable' => false, 'signboard_available' => null, 'occurred_at' => '', 'site_address' => 'Synthetic field address', 'availability' => 'available', 'visit_status' => 'operating', 'contact_result' => 'customer_contacted', 'outcome' => 'successful_visit', 'visit_remarks' => 'Synthetic field observation'], (string) Str::uuid7());
    }

    return [$actor, $first->batch, $tasks->map->refresh()];
}

function batchFlowInput(Collection $tasks, array $overrides = []): array
{
    return array_replace(['operation' => 'dr_code', 'value' => 'DR05', 'reason' => 'Confirmed during the field visit', 'selection' => $tasks->pluck('public_id')->all()], $overrides);
}

function batchFlowProof(User $actor, Task $task, string $category = 'premises'): Document
{
    return Document::factory()->create(['owner_type' => 'task', 'owner_id' => $task->id, 'activity_id' => $task->activities()->latest('id')->firstOrFail()->id, 'uploaded_by' => $actor->id, 'document_type_code' => 'proof_image', 'image_category_code' => $category, 'scan_status' => 'clean']);
}

test('Rider batch updates preserve previous observations and exact task selection', function (): void {
    [$actor, $batch, $tasks] = batchFlowFixture();
    $updates = app(RiderBatchUpdate::class);
    $preview = $updates->preview($actor, $batch, batchFlowInput($tasks));
    expect($updates->apply($actor, $preview))->toBe(2);
    foreach ($tasks as $task) {
        expect($task->activities()->latest('id')->first()->inspection->dr_code)->toBe('DR05');
    }
    $updates->apply($actor, $updates->preview($actor, $batch, batchFlowInput($tasks->take(1), ['value' => 'DR06'])));
    expect($tasks[0]->activities()->latest('id')->first()->inspection->dr_code)->toBe('DR06')
        ->and($tasks[1]->activities()->latest('id')->first()->inspection->dr_code)->toBe('DR05')
        ->and(PropertyInspection::query()->where('dr_code', 'DR05')->count())->toBe(2)
        ->and(CollectionActivity::query()->where('performed_by', $actor->id)->count())->toBe(2);
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $actor->id, 'action' => 'rider_batch_applied']);
    expect(fn () => $updates->apply($actor, $preview))->toThrow(ValidationException::class);
});

test('a stale task or revoked permission blocks the entire batch', function (string $change): void {
    [$actor, $batch, $tasks] = batchFlowFixture();
    $updates = app(RiderBatchUpdate::class);
    $preview = $updates->preview($actor, $batch, batchFlowInput($tasks));
    if ($change === 'permission') {
        $actor->syncRoles([]);
    } else {
        $tasks[1]->increment('lock_version');
    }
    try {
        $updates->apply($actor, $preview);
        $this->fail('An invalidated preview must not be applied.');
    } catch (ValidationException|HttpException|AuthorizationException) {
        expect(PropertyInspection::query()->count())->toBe(0);
    }
})->with(['permission', 'version']);

test('preview rejects unavailable selections and inactive lookup values', function (): void {
    [$actor, $batch, $tasks] = batchFlowFixture();
    $other = Task::factory()->create();
    $updates = app(RiderBatchUpdate::class);
    expect(fn () => $updates->preview($actor, $batch, batchFlowInput($tasks, ['selection' => [$other->public_id]])))->toThrow(ValidationException::class);
    expect(fn () => $updates->preview($actor, $batch, batchFlowInput($tasks, ['value' => ['DR05', 'DR06']])))->toThrow(ValidationException::class);
    ReferenceValue::query()->where('category', 'dr_code')->where('code', 'DR05')->update(['is_active' => false]);
    expect(fn () => $updates->preview($actor, $batch, batchFlowInput($tasks)))->toThrow(ValidationException::class);
});

test('a signed preview cannot be tampered with or reused after expiry', function (string $change): void {
    [$actor, $batch, $tasks] = batchFlowFixture();
    $updates = app(RiderBatchUpdate::class);
    $preview = $updates->preview($actor, $batch, batchFlowInput($tasks));
    if ($change === 'expiry') {
        $this->travel(16)->minutes();
    } else {
        $preview['input']['value'] = 'DR06';
    }
    expect(fn () => $updates->apply($actor, $preview))->toThrow(ValidationException::class);
    expect(PropertyInspection::query()->count())->toBe(0);
})->with(['expiry', 'value']);

test('batch submission respects clean proof and completes tasks without a payment automatically', function (): void {
    [$actor, $batch, $tasks] = batchFlowFixture();
    $updates = app(RiderBatchUpdate::class);
    $input = batchFlowInput($tasks, ['operation' => 'submit', 'value' => '']);
    $blocked = $updates->preview($actor, $batch, $input);
    expect($blocked['rows'][0]['issues'])->not->toBeEmpty();
    expect(fn () => $updates->apply($actor, $blocked))->toThrow(ValidationException::class);
    foreach ($tasks as $task) {
        batchFlowProof($actor, $task);
    }
    $ready = $updates->preview($actor, $batch, $input);
    expect($ready['rows'][0]['summary']['remarks']['visit'])->toBe('Synthetic field observation')->and($ready['rows'][0]['after'])->toBe('Completed')
        ->and(array_keys($ready['rows'][0]['proof'][0]))->toBe(['reference', 'label', 'category', 'status']);
    expect($updates->apply($actor, $ready))->toBe(2);
    foreach ($tasks as $task) {
        expect($task->refresh()->work_status)->toBe('completed')->and($task->review_status)->toBe('approved');
    }
    expect(fn () => $updates->apply($actor, $updates->preview($actor, $batch, batchFlowInput($tasks))))->toThrow(ValidationException::class);
});

test('batch reminders replace pending reminders while retaining their history', function (): void {
    [$actor, $batch, $tasks] = batchFlowFixture(1);
    $updates = app(RiderBatchUpdate::class);
    $value = now()->addDays(2)->timezone(config('dcms.business_timezone'))->format('Y-m-d\TH:i');
    $input = batchFlowInput($tasks, ['operation' => 'reminder', 'value' => $value]);
    $updates->apply($actor, $updates->preview($actor, $batch, $input));
    $updates->apply($actor, $updates->preview($actor, $batch, $input));
    expect(FollowUp::query()->where('status', 'pending')->count())->toBe(1)->and(FollowUp::query()->where('status', 'cancelled')->count())->toBe(1);
});

test('visit date updates retain the earlier visit and require proof for the new draft', function (): void {
    [$actor, $batch, $tasks] = batchFlowFixture(1);
    $proof = batchFlowProof($actor, $tasks[0]);
    $updates = app(RiderBatchUpdate::class);
    $input = batchFlowInput($tasks, ['operation' => 'visit_date', 'value' => now()->subHour()->timezone(config('dcms.business_timezone'))->format('Y-m-d\TH:i')]);
    $updates->apply($actor, $updates->preview($actor, $batch, $input));
    $review = app(CompletionChecklist::class)->review($tasks[0]->refresh(), $actor);
    expect(CollectionActivity::query()->where('performed_by', $actor->id)->count())->toBe(2)->and($review['issues'])->toHaveKey('proof')->and($proof->refresh()->activity_id)->not->toBe($review['visit']->id);
});

test('Rider manage screen previews cancels and applies through the shared task policy', function (): void {
    [$actor, $batch, $tasks] = batchFlowFixture();
    $this->get(route('rider.tasks.manage'))->assertRedirect(route('rider.login'));
    Livewire::actingAs($actor)->test(Manage::class)->set('batch', $batch->public_id)->set('selection', $tasks->pluck('public_id')->all())->set('value', 'DR05')->set('reason', 'Synthetic confirmed visit')->call('previewUpdates')->assertSee('Review selected update')->call('cancelPreview')->assertSet('preview', [])->call('previewUpdates')->call('applyUpdates')->assertHasNoErrors()->assertSet('selection', []);
    $actor->revokePermissionTo('tasks.submit');
    $actor->syncRoles([]);
    $actor->givePermissionTo(['rider.access', 'tasks.view']);
    $this->actingAs($actor)->get(route('rider.tasks.manage'))->assertForbidden();
});

test('DR payment and promise codes retain evidence and follow-up requirements', function (): void {
    [$actor, $batch, $tasks] = batchFlowFixture(1);
    batchFlowProof($actor, $tasks[0]);
    $updates = app(RiderBatchUpdate::class);
    $updates->apply($actor, $updates->preview($actor, $batch, batchFlowInput($tasks, ['value' => 'DR01'])));
    expect(app(CompletionChecklist::class)->review($tasks[0]->refresh(), $actor)['issues'])->toHaveKey('proof');
    $updates->apply($actor, $updates->preview($actor, $batch, batchFlowInput($tasks, ['value' => 'DR02'])));
    expect(app(CompletionChecklist::class)->review($tasks[0]->refresh(), $actor)['issues'])->toHaveKey('follow_up');
});

test('proof references are visible stable and different for different uploads', function (): void {
    [$actor, $batch, $tasks] = batchFlowFixture(1);
    $first = batchFlowProof($actor, $tasks[0]);
    $second = batchFlowProof($actor, $tasks[0]);
    expect($first->proofReference())->toStartWith('IMG-')->not->toBe($second->proofReference());
    $this->actingAs($actor)->get(route('rider.tasks.show', ['task' => $tasks[0], 'section' => 'proof']))->assertOk()->assertSee($first->proofReference());
});

test('business nature and configured property codes append inspection versions without detaching proof', function (): void {
    [$actor, $batch, $tasks] = batchFlowFixture(1);
    $task = $tasks[0];
    $visit = $task->activities()->latest('id')->firstOrFail();
    $initial = new PropertyInspection;
    $initial->forceFill(['task_id' => $task->id, 'activity_id' => $visit->id, 'property_id' => $task->account->property_id, 'observed_at' => $visit->occurred_at, 'business_type_code' => 'sole_proprietorship', 'business_nature_code' => 'retail', 'business_status_code' => 'active'])->save();
    $proof = batchFlowProof($actor, $task);
    $updates = app(RiderBatchUpdate::class);
    $updates->apply($actor, $updates->preview($actor, $batch, batchFlowInput($tasks, ['operation' => 'business_nature', 'value' => 'NB01'])));
    foreach (['property_usage' => 'usage', 'property_type' => 'property_type'] as $category => $operation) {
        ReferenceValue::unguarded(fn () => ReferenceValue::query()->create(['category' => $category, 'code' => 'synthetic_option', 'label' => 'Synthetic configured option']));
        $updates->apply($actor, $updates->preview($actor, $batch, batchFlowInput($tasks, ['operation' => $operation, 'value' => 'synthetic_option'])));
    }
    $current = $visit->fresh()->inspection;
    expect($current->business_nature_code)->toBe('NB01')->and($current->usage_code)->toBe('synthetic_option')->and($current->property_type_code)->toBe('synthetic_option')->and($initial->fresh()->business_nature_code)->toBe('retail')->and($proof->refresh()->activity_id)->toBe($visit->id);
    $snapshot = app(CompletionChecklist::class)->review($task->refresh(), $actor)['snapshot'];
    expect($snapshot['business']['nature'])->toBe('NB01')->and($snapshot['business']['usage'])->toBe('synthetic_option');
});

test('one blocked task prevents edits to every other task in the selection', function (): void {
    [$actor, $batch, $tasks] = batchFlowFixture();
    $proof = batchFlowProof($actor, $tasks[1]);
    $review = app(CompletionChecklist::class)->review($tasks[1]->refresh(), $actor);
    app(RiderWork::class)->submit($actor, $tasks[1], $tasks[1]->lock_version, (string) Str::uuid7(), $review['digest']);
    $updates = app(RiderBatchUpdate::class);
    $preview = $updates->preview($actor, $batch, batchFlowInput($tasks));
    expect(fn () => $updates->apply($actor, $preview))->toThrow(ValidationException::class);
    expect(PropertyInspection::query()->count())->toBe(0)->and($tasks[0]->refresh()->work_status)->toBe('in_progress');
});

test('batch selection and visit time validation reject unsafe requests', function (): void {
    [$actor, $batch, $tasks] = batchFlowFixture(1);
    $updates = app(RiderBatchUpdate::class);
    $oversized = array_map(fn () => (string) Str::uuid7(), range(1, 51));
    expect(fn () => $updates->preview($actor, $batch, batchFlowInput($tasks, ['selection' => $oversized])))->toThrow(ValidationException::class);
    foreach ([now()->addDay(), now()->subDays(3)] as $time) {
        expect(fn () => $updates->preview($actor, $batch, batchFlowInput($tasks, ['operation' => 'visit_date', 'value' => $time->timezone(config('dcms.business_timezone'))->format('Y-m-d\TH:i')])))->toThrow(ValidationException::class);
    }
});
