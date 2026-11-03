<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** M05 / Database.md: canonical account, task and allocation entities. */
class Task extends Model
{
    use HasFactory,HasPublicId,SoftDeletes;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['source_snapshot_ciphertext', 'address_lines_ciphertext', 'special_instruction_ciphertext', 'task_remarks_ciphertext', 'admin_remarks_ciphertext'];

    protected function casts(): array
    {
        return ['source_snapshot_ciphertext' => 'encrypted:array', 'special_instruction_ciphertext' => 'encrypted', 'task_remarks_ciphertext' => 'encrypted', 'admin_remarks_ciphertext' => 'encrypted', 'lock_version' => 'integer', 'excluded' => 'boolean', 'payment_update_flag' => 'boolean', 'scheduled_visit_at' => 'immutable_datetime', 'due_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AssignmentBatch::class, 'assignment_batch_id');
    }

    /** Tasks imported from one uploaded Excel file, or created manually and grouped with it. */
    public function scopeFromImport(Builder $query, int $importBatchId): void
    {
        $query->where(fn (Builder $query) => $query->where('import_batch_id', $importBatchId)
            ->orWhereIn('source_import_row_id', ImportRow::query()->where('import_batch_id', $importBatchId)->select('id')));
    }

    /** Task IDs are read by their last run of digits: FG001 is 1, DT2304 is 2304. */
    public static function numberFromId(string $id): ?int
    {
        if (! preg_match('/(\d+)\D*$/', $id, $match) || strlen($match[1]) > 18) {
            return null;
        }

        return (int) $match[1];
    }

    public function balances(): HasMany
    {
        return $this->hasMany(FinancialSnapshot::class)->orderBy('id');
    }

    public function latestBalance(): HasOne
    {
        return $this->hasOne(FinancialSnapshot::class)->latestOfMany('id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(DebtAccount::class, 'debt_account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Debtor::class, 'customer_id');
    }

    public function currentAllocation(): HasOne
    {
        return $this->hasOne(TaskAllocation::class)->whereNull('released_at');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(TaskAllocation::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(TaskStatusHistory::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CollectionActivity::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(PropertyInspection::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(TaskSubmission::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(TaskReview::class);
    }

    public function effectiveDue(): CarbonImmutable
    {
        return $this->due_at ?? CarbonImmutable::parse($this->batch->due_date->format('Y-m-d'), config('dcms.business_timezone'))->endOfDay()->utc();
    }
}
