<?php

use App\Actions\Tasks\ResolveTaskSource;
use App\Models\Contact;
use App\Models\Debtor;
use App\Services\Customers\CustomerSearch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    config(['dcms.search_key' => base64_encode(str_repeat('t', 32))]);
    $this->seed(DatabaseSeeder::class);
});

function searchableCustomer(): Debtor
{
    return app(ResolveTaskSource::class)->handle(staffManager(), ['customer_reference' => 'C-001', 'customer_name' => 'Nur Aisyah Tan', 'account_number' => 'A-001'])[0];
}

test('indexed search handles exact references contacts and name prefixes', function (string $kind, string $query, bool $found): void {
    $customer = searchableCustomer();
    Contact::factory()->create(['debtor_id' => $customer->id, 'kind_code' => 'email', 'value_ciphertext' => 'nur@example.test', 'value_fingerprint' => app(CustomerSearch::class)->token('contact:email', 'nur@example.test')]);

    $ids = app(CustomerSearch::class)->apply(Debtor::query(), $kind, $query)->pluck('id')->all();

    expect(in_array($customer->id, $ids))->toBe($found);
})->with([
    ['reference', 'C-001', true], ['reference', 'C-', false], ['name', 'aisy TAN', true], ['name', 'ai', false], ['name', 'missing', false],
    ['contact', 'nur@EXAMPLE.test', true], ['contact', 'nur@', false],
]);

test('keyed tokens omit plaintext and missing key fails closed', function (): void {
    $customer = searchableCustomer();
    expect(json_encode($customer->search_tokens))->not->toContain('aisyah', 'nur', 'tan');
    config(['dcms.search_key' => null]);

    expect(fn () => app(CustomerSearch::class)->names('Private Name'))->toThrow(LogicException::class);
});
