<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** M08: append-only record of what a customer owed at a point in time. Not a receipt. */
class FinancialSnapshot extends Model
{
    use HasFactory,HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['outstanding_balance_ciphertext', 'monthly_charge_ciphertext', 'paid_amount_ciphertext'];

    protected function casts(): array
    {
        return ['outstanding_balance_ciphertext' => 'encrypted', 'monthly_charge_ciphertext' => 'encrypted', 'paid_amount_ciphertext' => 'encrypted', 'balance_date' => 'immutable_date'];
    }

    /**
     * @param  array{outstanding?: ?int, monthly?: ?int, paid?: ?int, balance_date?: ?string, submission_id?: ?int}  $amounts  amounts in whole cents
     */
    public static function record(Task $task, User $actor, string $source, array $amounts): self
    {
        $snapshot = new self;
        $snapshot->forceFill([
            'task_id' => $task->id,
            'debt_account_id' => $task->debt_account_id,
            'recorded_by' => $actor->id,
            'submission_id' => $amounts['submission_id'] ?? null,
            'source_code' => $source,
            'outstanding_balance_ciphertext' => isset($amounts['outstanding']) ? Money::plain($amounts['outstanding']) : null,
            'monthly_charge_ciphertext' => isset($amounts['monthly']) ? Money::plain($amounts['monthly']) : null,
            'paid_amount_ciphertext' => isset($amounts['paid']) ? Money::plain($amounts['paid']) : null,
            'balance_date' => $amounts['balance_date'] ?? now()->timezone(config('dcms.business_timezone'))->toDateString(),
        ])->save();

        return $snapshot;
    }

    public function outstandingCents(): ?int
    {
        return Money::cents($this->outstanding_balance_ciphertext);
    }

    public function monthlyCents(): ?int
    {
        return Money::cents($this->monthly_charge_ciphertext);
    }

    public function paidCents(): ?int
    {
        return Money::cents($this->paid_amount_ciphertext);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
