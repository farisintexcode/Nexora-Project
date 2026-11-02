<?php

namespace App\Services\Imports;

use App\Actions\Assignments\SaveAssignmentBatch;
use App\Actions\Tasks\ResolveTaskSource;
use App\Actions\Tasks\SaveTask;
use App\Models\AssignmentBatch;
use App\Models\DebtAccount;
use App\Models\Debtor;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** M04 → M03/M05: exact portfolio identity, existing writers, optimistic concurrency. */
class ImportTargets
{
    public function prepare(User $actor, ImportBatch $batch, array $values): array
    {
        $target = null;
        $revision = null;
        if ($batch->kind !== 'task') {
            $reference = trim($values['external_reference'] ?? '');
            if ($reference === '') {
                throw ValidationException::withMessages(['external_reference' => 'required']);
            }
            $target = AssignmentBatch::query()->where('external_reference', $reference)->first();
            $revision = $target?->lock_version;
        }
        $payload = ['input' => $values, 'target_id' => $target?->id, 'revision' => $revision, 'operation' => $target ? 'update' : 'create', 'changes' => []];
        foreach ($values as $field => $value) {
            $payload['changes'][$field] = ['before' => $this->mask($target?->getAttribute($field)), 'after' => $this->mask($value)];
        }
        // Exercise the real writer under a savepoint; no customer, account, task, batch or audit survives preview.
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $this->write($actor, $batch, $payload);
        } finally {
            DB::rollBack($level);
        }

        return $payload;
    }

    public function write(User $actor, ImportBatch $batch, array $payload, ?ImportRow $row = null): AssignmentBatch|Task
    {
        if ($batch->kind === 'task') {
            [$customer, $account] = $this->taskSource($actor, $batch, $payload['input']);

            return app(SaveTask::class)->handle($actor, ['assignment_batch_id' => $batch->assignment_batch_id, 'task_id' => $payload['input']['task_id'] ?? null, 'customer_id' => $customer->id, 'debt_account_id' => $account->id, 'outstanding_balance' => $payload['input']['outstanding_balance'] ?? null, 'monthly_charge' => $payload['input']['monthly_charge'] ?? null, 'balance_date' => $payload['input']['balance_date'] ?? null, 'priority_code' => mb_strtolower(trim($payload['input']['priority_code'] ?? '')) ?: 'normal', 'instructions' => $payload['input']['instructions'] ?? null], source: $row);
        }
        $target = null;
        $current = AssignmentBatch::query()->where('external_reference', trim($payload['input']['external_reference']))->first();
        if ($payload['target_id'] === null && $current !== null) {
            throw ValidationException::withMessages(['revision' => 'target_changed']);
        }
        if ($payload['target_id'] !== null) {
            if (! $current || $current->id !== $payload['target_id']) {
                throw ValidationException::withMessages(['revision' => 'target_changed']);
            }
            $target = $current;
        }

        return app(SaveAssignmentBatch::class)->handle($actor, $payload['input'], $target, $payload['revision']);
    }

    /**
     * Resolves the customer and account a task row refers to, creating them when new; an account gets one open task per assignment.
     *
     * @param  array<string, string>  $values
     * @return array{0: Debtor, 1: DebtAccount}
     */
    private function taskSource(User $actor, ImportBatch $batch, array $values): array
    {
        $number = Task::numberFromId(trim($values['task_id'] ?? ''));
        if ($number === null) {
            throw ValidationException::withMessages(['task_id' => 'invalid_task_id']);
        }
        if (Task::query()->where('assignment_batch_id', $batch->assignment_batch_id)->where('source_task_number', $number)->exists()) {
            throw ValidationException::withMessages(['task_id' => 'task_id_exists']);
        }
        $account = DebtAccount::query()->where('account_number', trim($values['account_number'] ?? ''))->first();
        if ($account && Task::query()->where('assignment_batch_id', $batch->assignment_batch_id)->where('debt_account_id', $account->id)->where('work_status', '<>', 'cancelled')->exists()) {
            throw ValidationException::withMessages(['account_number' => 'task_exists']);
        }

        return app(ResolveTaskSource::class)->handle($actor, $values);
    }

    public function mask(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $text = is_scalar($value) ? (string) $value : '';

        return mb_strlen($text) > 4 ? mb_substr($text, 0, 2).'•••'.mb_substr($text, -2) : '•••';
    }
}
