<?php

namespace Database\Factories;

use App\Models\DebtAccount;
use App\Models\FinancialSnapshot;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinancialSnapshot> */
class FinancialSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'debt_account_id' => DebtAccount::factory(),
            'recorded_by' => User::factory(),
            'source_code' => 'manual',
            'outstanding_balance_ciphertext' => '1000.00',
            'monthly_charge_ciphertext' => '50.00',
            'balance_date' => '2026-11-01',
        ];
    }
}
