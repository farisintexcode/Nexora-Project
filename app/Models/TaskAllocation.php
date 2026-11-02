<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** M05 / Database.md: canonical account, task and allocation entities. */
class TaskAllocation extends Model
{
    use HasFactory,HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['source_snapshot_ciphertext', 'address_lines_ciphertext', 'special_instruction_ciphertext', 'task_remarks_ciphertext', 'admin_remarks_ciphertext'];

    protected function casts(): array
    {
        return ['assigned_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'collector_id');
    }
}
