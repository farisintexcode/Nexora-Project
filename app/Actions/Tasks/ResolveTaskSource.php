<?php

namespace App\Actions\Tasks;

use App\Actions\Accounts\SaveDebtAccount;
use App\Models\DebtAccount;
use App\Models\Debtor;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Customers\CustomerContacts;
use App\Services\Customers\CustomerSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** M05 intake: finds the customer and account a new task refers to, creating minimal records the first time a reference appears. */
class ResolveTaskSource
{
    private const DEFAULT_CURRENCY = 'MYR';

    private const DEFAULT_PARTY_ROLE = 'billing_customer';

    public function __construct(private CustomerSearch $search, private AuditWriter $audit, private SaveDebtAccount $accounts, private CustomerContacts $contacts) {}

    /**
     * @param  array{customer_reference?: ?string, customer_name?: ?string, phone?: ?string, email?: ?string, account_number?: ?string, address?: ?string, building?: ?string, postcode?: ?string, state_code?: ?string}  $input
     * @return array{0: Debtor, 1: DebtAccount}
     */
    public function handle(User $actor, array $input): array
    {
        return DB::transaction(function () use ($actor, $input): array {
            Gate::forUser($actor)->authorize('create', Task::class);
            $customer = $this->customer($actor, trim($input['customer_reference'] ?? ''), trim($input['customer_name'] ?? ''), trim($input['phone'] ?? ''), trim($input['email'] ?? ''));
            $number = trim($input['account_number'] ?? '');
            if ($number === '') {
                throw ValidationException::withMessages(['account_number' => 'required']);
            }

            return [$customer, DebtAccount::query()->where('account_number', $number)->first() ?? $this->accounts->handle($actor, $customer, [
                'account_number' => $number,
                'currency' => self::DEFAULT_CURRENCY,
                'status' => 'active',
                'role_code' => self::DEFAULT_PARTY_ROLE,
                'address' => $input['address'] ?? null,
                'building' => $input['building'] ?? null,
                'postcode' => $input['postcode'] ?? null,
                'state_code' => str_replace(' ', '_', mb_strtolower(trim($input['state_code'] ?? ''))) ?: null,
            ])];
        }, 3);
    }

    private function customer(User $actor, string $reference, string $name, string $phone, string $email): Debtor
    {
        if ($reference === '') {
            throw ValidationException::withMessages(['customer_reference' => 'required']);
        }
        if (mb_strlen($reference) > 80) {
            throw ValidationException::withMessages(['customer_reference' => 'too_long']);
        }
        $existing = Debtor::query()->where('customer_reference', $reference)->first();
        if ($existing) {
            return $existing;
        }
        if ($name === '') {
            throw ValidationException::withMessages(['customer_name' => 'required']);
        }
        if (mb_strlen($name) > 200) {
            throw ValidationException::withMessages(['customer_name' => 'too_long']);
        }
        $customer = new Debtor;
        $customer->customer_reference = $reference;
        $customer->party_type = 'person';
        $customer->display_name_ciphertext = $name;
        $customer->search_tokens = $this->search->names($name);
        $customer->status = 'active';
        $customer->save();
        $contacts = array_values(array_filter([
            $phone === '' ? null : ['kind' => 'mobile', 'value' => $phone, 'is_primary' => true],
            $email === '' ? null : ['kind' => 'email', 'value' => $email, 'is_primary' => true],
        ]));
        if ($contacts !== []) {
            $this->contacts->validate($contacts);
            $this->contacts->sync($customer, $contacts, $actor, 'task_intake');
        }
        $this->audit->record($actor, 'customer_created', $customer->public_id, ['status' => $customer->status], module: 'M05', entityType: 'debtor');

        return $customer->refresh();
    }
}
