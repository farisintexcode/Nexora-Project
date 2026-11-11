<?php

use App\Actions\Communications\ManageTemplates;
use App\Livewire\Admin\Communications\Templates\Editor;
use App\Models\AuditLog;
use App\Models\CommunicationTemplate;
use App\Models\Debtor;
use App\Models\IntegrationAccount;
use App\Models\Task;
use App\Models\User;
use App\Services\Communications\TemplateContext;
use App\Services\Communications\TemplateRenderer;
use Database\Seeders\CommunicationTemplateSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});
function templateDefault(int $slot = 1): CommunicationTemplate
{
    return CommunicationTemplate::query()->where('email_slot', $slot)->where('version', 1)->firstOrFail();
}
function templateDraft(?User $author = null, int $slot = 1): CommunicationTemplate
{
    return app(ManageTemplates::class)->createVersion($author ?? staffManager(), templateDefault($slot));
}
function templateWording(array $replace = []): array
{
    return array_replace(['name' => 'Synthetic notice', 'subject' => 'Notice for {{customer_name}}', 'body' => 'Hello {{customer_name}} from {{company_name}}.', 'required' => ['customer_name']], $replace);
}
function templateApprover(): User
{
    $actor = User::factory()->create();
    $actor->assignRole('Admin');
    $actor->givePermissionTo('templates.approve');

    return $actor;
}
function submittedTemplate(User $author, array $input = []): CommunicationTemplate
{
    $manager = app(ManageTemplates::class);
    $saved = $manager->save($author, templateDraft($author), 0, templateWording($input));

    return $manager->submit($author, $saved, 1);
}

test('three approved owner defaults seed idempotently without fabricating approvers or activating providers', function (): void {
    $this->seed(CommunicationTemplateSeeder::class);
    expect(CommunicationTemplate::query()->count())->toBe(3)->and(CommunicationTemplate::query()->where('status', 'approved')->count())->toBe(3)->and(IntegrationAccount::query()->count())->toBe(0);
    $default = templateDefault();
    expect($default->name)->toBe('Friendly Payment Reminder')->and($default->subject_template)->toBe('Payment Reminder for Account {{account_number}}')->and($default->approved_by)->toBeNull()->and($default->approval_source)->toBe('owner_default')->and($default->approved_at)->not->toBeNull();
    expect(collect($default->allowed_placeholders)->filter(fn ($v) => $v['required'])->count())->toBe(5);
    $admin = staffManager();
    expect($admin->can('templates.approve'))->toBeFalse();
    expect(Gate::forUser($admin)->allows('approve', templateDraft($admin)))->toBeFalse();
});

test('template workspace enforces administrative communication grants', function (): void {
    $this->get(route('admin.communications.index'))->assertRedirect(route('login'));
    $actor = staffManager();
    $this->actingAs($actor)->get(route('admin.communications.index'))->assertOk()->assertSee('Communications')->assertSee('View approved option 1')->assertSee('Friendly Payment Reminder')
        ->assertSee('From wording to delivery')->assertSee('Sending readiness')->assertSee('Preview only')->assertSee('Example · not a real message');
    $this->get(route('admin.communications.templates.edit', templateDefault()))->assertOk()->assertSee('Saved wording');
    $this->get(route('admin.communications.templates.edit', ['template' => templateDefault()->public_id, 'customer' => (string) Str::uuid7()]))->assertNotFound();
    $actor->syncRoles([]);
    $actor->givePermissionTo('admin.access');
    $this->get(route('admin.communications.index'))->assertForbidden();
});

test('draft saves validate placeholders revisions and keep wording out of audit', function (): void {
    $actor = staffManager();
    $template = templateDraft($actor);
    $saved = app(ManageTemplates::class)->save($actor, $template, 0, templateWording());
    expect($saved->lock_version)->toBe(1)->and($saved->allowed_placeholders['customer_name']['required'])->toBeTrue()->and($saved->allowed_placeholders['company_name']['required'])->toBeFalse();
    expect(fn () => app(ManageTemplates::class)->save($actor, $template, 0, templateWording()))->toThrow(ValidationException::class);
    expect(AuditLog::query()->where('module', 'M14')->get()->toJson())->not->toContain('Hello')->not->toContain('Notice for');
});

test('invalid wording gives an explicit validation failure without changing the draft', function (array $input, string $message): void {
    $actor = staffManager();
    $draft = templateDraft($actor);
    try {
        app(ManageTemplates::class)->save($actor, $draft, 0, templateWording($input));
        $this->fail('Invalid wording was accepted.');
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->implode(' '))->toContain($message);
    }
    expect($draft->refresh()->lock_version)->toBe(0);
})->with([
    'unknown token' => [['body' => 'Hi {{secret}}'], 'Unknown placeholder'],
    'expression' => [['body' => '{{phpinfo()}}'], 'approved {{placeholder}}'],
    'raw Blade' => [['body' => '{!! $secret !!}'], 'approved {{placeholder}}'],
    'unbalanced token' => [['body' => '{{customer_name'], 'approved {{placeholder}}'],
    'header injection' => [['subject' => "Hello\r\nBcc: stranger@example.test"], 'single line'],
    'unused required' => [['required' => ['due_date']], 'must be approved placeholders used'],
    'unknown required' => [['required' => ['secret']], 'must be approved placeholders used'],
]);

test('submission freezes exact saved content and separate approval atomically archives the old default', function (): void {
    $author = staffManager();
    $manager = app(ManageTemplates::class);
    $pending = submittedTemplate($author);
    expect($pending->status)->toBe('pending_approval')->and($pending->submitted_by)->toBe($author->id)->and(templateDefault()->status)->toBe('approved');
    expect(fn () => $manager->save($author, $pending, 2, templateWording()))->toThrow(AuthorizationException::class);
    expect(fn () => $manager->approve($author, $pending, 2))->toThrow(AuthorizationException::class);
    $approver = templateApprover();
    $approved = $manager->approve($approver, $pending, 2);
    expect($approved->approved_by)->toBe($approver->id)->and($approved->status)->toBe('approved')->and($approved->approval_source)->toBe('authorized_user')->and(templateDefault()->status)->toBe('archived');
    expect(CommunicationTemplate::query()->where('email_slot', 1)->where('status', 'approved')->count())->toBe(1);
    $this->seed(CommunicationTemplateSeeder::class);
    expect($approved->refresh()->status)->toBe('approved')->and(templateDefault()->status)->toBe('archived');
});

test('rejection requires a reason and retains immutable reviewed content while the existing approved version stays active', function (): void {
    $author = staffManager();
    $manager = app(ManageTemplates::class);
    $pending = submittedTemplate($author);
    $approver = templateApprover();
    expect(fn () => $manager->reject($approver, $pending, 2, '  '))->toThrow(ValidationException::class);
    $rejected = $manager->reject($approver, $pending, 2, 'Synthetic wording requires legal review');
    expect($rejected->status)->toBe('rejected')->and($rejected->rejected_by)->toBe($approver->id)->and($rejected->rejection_reason_ciphertext)->toBe('Synthetic wording requires legal review')->and(templateDefault()->status)->toBe('approved');
    expect(DB::table('communication_templates')->where('id', $rejected->id)->value('rejection_reason_ciphertext'))->not->toContain('Synthetic');
    expect($rejected->toArray())->not->toHaveKey('rejection_reason_ciphertext');
    expect(fn () => DB::transaction(fn () => DB::table('communication_templates')->where('id', $rejected->id)->update(['body_template' => 'Changed', 'lock_version' => 4])))->toThrow(QueryException::class);
    $revision = $manager->createVersion($author, $rejected);
    expect($revision->version)->toBe(3)->and($revision->supersedes_id)->toBe($rejected->id);
});

test('blank drafts cannot be submitted and published versions cannot be rewritten deleted or reactivated', function (): void {
    $actor = templateApprover();
    $manager = app(ManageTemplates::class);
    $draft = templateDraft($actor);
    $blank = $manager->save($actor, $draft, 0, templateWording(['subject' => '', 'body' => '', 'required' => []]));
    expect(fn () => $manager->submit($actor, $blank, 1))->toThrow(ValidationException::class);
    $approved = templateDefault();
    expect(fn () => DB::transaction(fn () => DB::table('communication_templates')->where('id', $approved->id)->update(['body_template' => 'Changed', 'lock_version' => 1])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('communication_templates')->where('id', $approved->id)->delete()))->toThrow(QueryException::class);
    $archived = $manager->archive($actor, $approved, 0);
    expect($archived->status)->toBe('archived')->and($archived->approval_source)->toBe('owner_default');
    expect(fn () => DB::transaction(fn () => DB::table('communication_templates')->where('id', $archived->id)->update(['status' => 'approved', 'lock_version' => 2])))->toThrow(QueryException::class);
});

test('database rejects content substitution at review and a fourth logical email slot', function (): void {
    $actor = templateApprover();
    $pending = submittedTemplate($actor);
    expect(fn () => DB::transaction(fn () => DB::table('communication_templates')->where('id', $pending->id)->update(['status' => 'approved', 'body_template' => 'Substituted', 'approved_by' => $actor->id, 'approval_source' => 'authorized_user', 'approved_at' => now(), 'lock_version' => 3])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => CommunicationTemplate::factory()->create(['code' => 'email_4', 'email_slot' => 4])))->toThrow(QueryException::class);
});

test('renderer substitutes literal values once and lists optional missing data', function (): void {
    $template = CommunicationTemplate::factory()->make(['subject_template' => 'For {{ customer_name }}', 'body_template' => '{{customer_name}} / {{outstanding_amount}}', 'allowed_placeholders' => ['customer_name' => ['required' => true], 'outstanding_amount' => ['required' => false]]]);
    $result = app(TemplateRenderer::class)->render($template, ['customer_name' => '<script>{{company_name}}</script>']);
    expect($result['subject'])->toBe('For <script>{{company_name}}</script>')->and($result['body'])->toBe('<script>{{company_name}}</script> / ')->and($result['missing_optional'])->toBe(['outstanding_amount']);
});

test('actual customer previews use the configured company name and preserve account and missing financial provenance boundaries', function (): void {
    $actor = staffManager();
    $customer = Debtor::factory()->create();
    $context = app(TemplateContext::class);
    $data = $context->values($actor, $customer->public_id);
    expect($data['values']['customer_name'])->toBe($customer->display_name_ciphertext)->and($data['values']['company_name'])->toBe(config('communications.company_name'))->and($data['values']['outstanding_amount'])->toBeNull()->and($data['values']['due_date'])->toBeNull();
    expect(fn () => app(TemplateRenderer::class)->render(templateDefault(), $data['values']))->toThrow(ValidationException::class);
    $task = Task::factory()->create();
    expect(fn () => $context->values($actor, $customer->public_id, taskId: $task->public_id))->toThrow(ModelNotFoundException::class);
});

test('Livewire previews escape customer markup and submission refuses unsaved edits', function (): void {
    Mail::fake();
    $actor = staffManager();
    $customer = Debtor::factory()->create(['display_name_ciphertext' => '<img src=x onerror=alert(1)>']);
    $saved = app(ManageTemplates::class)->save($actor, templateDraft($actor), 0, templateWording());
    $this->actingAs($actor);
    Livewire::test(Editor::class, ['template' => $saved])->set('customerId', $customer->public_id)->call('preview', 'customer')->assertHasNoErrors()->assertSee('<img src=x onerror=alert(1)>')->assertDontSeeHtml('<img src=x onerror=alert(1)>')->set('form.body', 'Unsaved edit')->call('submitForApproval')->assertHasErrors('revision');
    expect($saved->refresh()->status)->toBe('draft');
    Mail::assertNothingSent();
});
