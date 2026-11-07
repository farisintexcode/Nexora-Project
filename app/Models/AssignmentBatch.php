<?php

namespace App\Models;

use App\Enums\BatchStatus;
use App\Models\Concerns\HasPublicId;
use Database\Factories\AssignmentBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssignmentBatch extends Model
{
    /** @use HasFactory<AssignmentBatchFactory> */
    use HasFactory;

    use HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['start_date' => 'date', 'due_date' => 'date', 'archived_at' => 'immutable_datetime', 'lock_version' => 'integer', 'status' => BatchStatus::class];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'type_code', 'code')->where('category', 'assignment_type');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'state_code', 'code')->where('category', 'state');
    }
}
