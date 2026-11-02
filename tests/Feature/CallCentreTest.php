<?php

use App\Actions\Calls\RecordCall;
use App\Actions\Calls\ResolveFollowUp;
use App\Actions\Customers\SaveCustomerContacts;
use App\Livewire\Admin\Calls\FollowUps;
use App\Livewire\Admin\Calls\Index;
use App\Livewire\Admin\Calls\Show;
use App\Models\AuditLog;
use App\Models\CollectionActivity;
use App\Models\Contact;
use App\Models\Debtor;
use App\Models\FollowUp;
use App\Models\ReferenceValue;
use App\Models\Task;
use App\Models\User;
use App\Services\Calls\CallAccess;
use App\Services\Customers\CustomerSearch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

function callFixture(): array
{
    $actor = staffManager();
    $customer = Debtor::factory()->create();
    $phone = '+60123456789';
    $contact = Contact::factory()->create(['debtor_id' => $customer->id, 'kind_code' => 'mobile', 'value_ciphertext' => $phone, 'value_fingerprint' => app(CustomerSearch::class)->token('contact:mobile', $phone)]);

    return [$actor, $customer, $contact];
}

function recordTestCall(User $actor, Debtor $customer, array $extra = [], ?string $key = null): CollectionActivity
{
    return app(RecordCall::class)->handle($actor, $customer, array_replace(['historical' => false, 'outcome' => 'answered', 'remarks' => 'Synthetic call discussion'], $extra), $key ?? (string) Str::uuid7());
}

test('call routes enforce permissions', function (): void {
    [$actor, $customer] = callFixture();
    $this->get(route('admin.calls.index'))->assertRedirect(route('login'));
    $this->actingAs($actor)->get(route('admin.calls.index'))->assertOk()->assertSee('Call Centre');
    $this->get(route('admin.calls.show', $customer))->assertOk()->assertSee('Record outgoing call');
    $this->get(route('admin.calls.follow-ups'))->assertOk();
    $actor->syncRoles([]);
    $actor->givePermissionTo(['admin.access', 'customers.view']);
    $this->get(route('admin.calls.index'))->assertForbidden();
});

test('taskless calls are encrypted append-only idempotent and auditable', function (): void {
    [$actor, $customer, $contact] = callFixture();
    $key = (string) Str::uuid7();
    $call = recordTestCall($actor, $customer, ['contact' => $contact->public_id], $key);
    expect($call->task_id)->toBeNull()->and($call->call_details_ciphertext['contact']['value'])->toBe('+60123456789');
    expect(recordTestCall($actor, $customer, [], $key)->id)->toBe($call->id);
    expect(CollectionActivity::query()->count())->toBe(1)->and(FollowUp::query()->count())->toBe(0);
    $raw = DB::table('collection_activities')->find($call->id);
    expect($raw->notes_ciphertext)->not->toContain('Synthetic')->and($raw->call_details_ciphertext)->not->toContain('+6012');
    expect($call->toArray())->not->toHaveKey('call_details_ciphertext');
    expect(AuditLog::query()->where('module', 'M11')->count())->toBe(1);
    expect(AuditLog::query()->where('module', 'M11')->get()->toJson())->not->toContain('Synthetic call');
    expect(fn () => DB::transaction(fn () => DB::table('collection_activities')->where('id', $call->id)->update(['outcome_code' => 'busy'])))->toThrow(QueryException::class);
});

test('approved calendar day backdating retains submission time and requires a reason', function (): void {
    $this->travelTo(now()->setTimezone('Asia/Kuala_Lumpur')->setDate(2026, 10, 18)->setTime(0, 5)->utc());
    [$actor, $customer] = callFixture();
    $call = recordTestCall($actor, $customer, ['historical' => true, 'occurred_at' => '2026-10-17T00:01', 'backdating_reason' => 'Offline field notes']);
    expect($call->occurred_at->timezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'))->toBe('2026-10-17 00:01')->and($call->backdating_reason_ciphertext)->toBe('Offline field notes')->and($call->created_at->greaterThan($call->occurred_at))->toBeTrue();
    foreach ([['2026-10-16T23:59', 'Old'], ['2026-10-18T00:06', 'Future'], ['2026-10-17T12:00', '  ']] as [$time, $reason]) {
        expect(fn () => recordTestCall($actor, $customer, ['historical' => true, 'occurred_at' => $time, 'backdating_reason' => $reason]))->toThrow(ValidationException::class);
    }
    $this->travelBack();
});

test('call outcomes require approved values and relevant details', function (array $input): void {
    [$actor, $customer] = callFixture();
    expect(fn () => recordTestCall($actor, $customer, $input))->toThrow(ValidationException::class);
    expect(CollectionActivity::query()->count())->toBe(0)->and(FollowUp::query()->count())->toBe(0);
})->with([
    'visit-only outcome' => [['outcome' => 'successful_visit']],
    'unknown outcome' => [['outcome' => 'invented']],
    'other detail' => [['outcome' => 'other']],
    'callback due' => [['outcome' => 'call_back_requested']],
    'promise detail' => [['outcome' => 'promise_to_pay', 'follow_up_at' => '2026-10-20T12:00']],
    'blank remarks' => [['remarks' => '   ']],
    'past followup' => [['follow_up_at' => '2020-01-01T12:00']],
    'invalid historical format' => [['historical' => true, 'occurred_at' => 'yesterday', 'backdating_reason' => 'Notes']],
]);

test('promise reminders preserve context and terminal decisions are immutable', function (): void {
    [$actor, $customer] = callFixture();
    $due = now()->addDay()->timezone('Asia/Kuala_Lumpur')->format('Y-m-d\TH:i');
    $call = recordTestCall($actor, $customer, ['outcome' => 'promise_to_pay', 'promise_notes' => 'Stated intention only', 'promised_amount' => '120.50', 'currency' => 'MYR', 'follow_up_at' => $due]);
    $follow = $call->followUps()->firstOrFail();
    expect($follow->assigned_to)->toBe($actor->id)->and($follow->customer_id)->toBe($customer->id)->and($call->call_details_ciphertext['promise']['stated_amount'])->toBe('120.50');
    expect(fn () => app(ResolveFollowUp::class)->handle($actor, $follow, 1, 'done', 'Done'))->toThrow(ValidationException::class);
    $done = app(ResolveFollowUp::class)->handle($actor, $follow, 0, 'done', 'Synthetic reviewed callback');
    expect($done->status)->toBe('done')->and($done->lock_version)->toBe(1)->and($done->resolved_by)->toBe($actor->id)->and($done->completed_at->equalTo($done->resolved_at))->toBeTrue();
    expect(app(ResolveFollowUp::class)->handle($actor, $follow, 0, 'done', 'Retry')->id)->toBe($follow->id);
    expect(DB::table('follow_ups')->value('resolution_ciphertext'))->not->toContain('Synthetic');
    expect(fn () => DB::transaction(fn () => DB::table('follow_ups')->where('id', $follow->id)->delete()))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('follow_ups')->where('id', $follow->id)->update(['due_at' => now()])))->toThrow(QueryException::class);
});

test('pending reminders reject context changes and nonowners but permit owner cancellation', function (): void {
    [$actor, $customer] = callFixture();
    $follow = recordTestCall($actor, $customer, ['follow_up_at' => now()->addDay()->timezone('Asia/Kuala_Lumpur')->format('Y-m-d\TH:i')])->followUps()->firstOrFail();
    $other = User::factory()->create();
    $other->assignRole('Admin');
    expect(fn () => app(ResolveFollowUp::class)->handle($other, $follow, 0, 'done', 'Unauthorized'))->toThrow(HttpException::class);
    expect(fn () => DB::transaction(fn () => DB::table('follow_ups')->where('id', $follow->id)->update(['activity_id' => null])))->toThrow(QueryException::class);
    $cancelled = app(ResolveFollowUp::class)->handle($actor, $follow, 0, 'cancelled', 'No further callback required');
    expect($cancelled->completed_at)->toBeNull()->and($cancelled->status)->toBe('cancelled');
});

test('contact-only edits preserve observations and reject stale or duplicate values', function (): void {
    [$actor, $customer, $contact] = callFixture();
    $actor->syncRoles([]);
    $actor->givePermissionTo(['admin.access', 'customers.view', 'calls.record', 'customers.update_contacts']);
    $revision = $customer->revision();
    $items = [['kind' => 'mobile', 'value' => '+60198765432', 'is_primary' => true]];
    app(SaveCustomerContacts::class)->handle($actor, $customer, $items, $revision);
    expect($contact->refresh()->valid_to)->not->toBeNull()->and($contact->value_ciphertext)->toBe('+60123456789');
    expect($customer->contacts()->whereNull('valid_to')->firstOrFail()->source)->toBe('call_centre:'.$actor->public_id);
    expect(fn () => app(SaveCustomerContacts::class)->handle($actor, $customer, [], $revision))->toThrow(ValidationException::class);
    expect(fn () => app(SaveCustomerContacts::class)->handle($actor, $customer, [$items[0], $items[0]], $customer->refresh()->revision()))->toThrow(ValidationException::class);
    app(SaveCustomerContacts::class)->handle($actor, $customer, [], $customer->refresh()->revision());
    expect($customer->contacts()->whereNull('valid_to')->count())->toBe(0);
});

test('call search uses current references phones task and account context and fails closed', function (): void {
    [$actor, $customer] = callFixture();
    $access = app(CallAccess::class);
    foreach ([['reference', $customer->customer_reference], ['contact', '+60123456789']] as [$kind, $query]) {
        expect($access->search($actor, ['searchBy' => $kind, 'query' => $query])->pluck('id')->all())->toBe([$customer->id]);
    }
    expect($access->search($actor, ['searchBy' => 'identifier'])->count())->toBe(0);
    $task = Task::factory()->create()->refresh();
    foreach ([['task', $task->public_id], ['account', $task->account->account_number]] as [$kind, $query]) {
        expect($access->search($actor->fresh(), ['searchBy' => $kind, 'query' => $query])->pluck('id')->all())->toBe([$task->customer_id]);
    }
    expect(fn () => recordTestCall($actor, $customer, ['task' => $task->public_id]))->toThrow(ModelNotFoundException::class);
});

test('Livewire form saves manual call and reads the shared timeline', function (): void {
    [$actor, $customer] = callFixture();
    $this->actingAs($actor);
    Livewire::test(Show::class, ['customer' => $customer])->set('form.outcome', 'answered')->set('form.remarks', 'Synthetic Livewire conversation')->call('saveCall')->assertHasNoErrors()->assertSee('Synthetic Livewire conversation');
    expect(CollectionActivity::query()->count())->toBe(1);
});

test('shared promise remains a visit outcome and call-only options have explicit purpose', function (): void {
    expect(ReferenceValue::query()->where('category', 'activity_outcome')->where('code', 'promise_to_pay')->firstOrFail()->metadata['activity_types'])->toContain('call', 'visit');
    expect(ReferenceValue::query()->where('category', 'activity_outcome')->where('code', 'answered')->firstOrFail()->metadata['activity_types'])->toBe(['call']);
});

test('calls recheck active outcome configuration', function (): void {
    [$actor, $customer] = callFixture();
    $key = (string) Str::uuid7();
    recordTestCall($actor, $customer, [], $key);
    ReferenceValue::query()->where('category', 'activity_outcome')->where('code', 'answered')->update(['is_active' => false]);
    expect(fn () => recordTestCall($actor, $customer))->toThrow(ValidationException::class);
    expect(CollectionActivity::query()->count())->toBe(1);
});

test('linked calls require task access and keep the selected phone snapshot after contact correction', function (): void {
    [$actor, $customer, $contact] = callFixture();
    $task = Task::factory()->create()->refresh();
    $call = recordTestCall($actor, $task->customer, ['task' => $task->public_id]);
    expect($call->task_id)->toBe($task->id);
    $this->actingAs($actor);
    Livewire::test(App\Livewire\Admin\Tasks\Show::class, ['task' => $task])->assertViewHas('visits', fn ($visits) => $visits->isEmpty());
    expect(fn () => recordTestCall($actor, $task->customer, ['contact' => $contact->public_id]))->toThrow(ModelNotFoundException::class);
    $call = recordTestCall($actor, $customer, ['contact' => $contact->public_id]);
    app(SaveCustomerContacts::class)->handle($actor, $customer, [['kind' => 'mobile', 'value' => '+60199999999', 'is_primary' => true]], $customer->revision());
    expect($call->refresh()->call_details_ciphertext['contact']['value'])->toBe('+60123456789');
    expect(fn () => recordTestCall($actor, $customer, ['contact' => $contact->public_id]))->toThrow(ModelNotFoundException::class);
});

test('call workspace denies contact actions without the separate grant', function (): void {
    [$actor, $customer] = callFixture();
    $actor->syncRoles([]);
    $actor->givePermissionTo(['admin.access', 'customers.view', 'calls.record']);
    $this->actingAs($actor);
    Livewire::test(Show::class, ['customer' => $customer])->assertSet('data.contacts', [])->call('editContacts')->assertForbidden();
});

test('call search and reminder queue retain pagination while filters reset it', function (): void {
    [$actor] = callFixture();
    $this->actingAs($actor);
    Livewire::test(Index::class)->call('setPage', 2)->assertSet('paginators.page', 2)->set('query', 'missing')->assertSet('paginators.page', 1);
    Livewire::test(FollowUps::class)->call('setPage', 2)->assertSet('paginators.page', 2)->set('status', 'all')->assertSet('paginators.page', 1);
});
