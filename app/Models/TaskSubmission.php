<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** M06 / Database.md canonical execution and submission history. */
class TaskSubmission extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['completion_snapshot_ciphertext'];

    protected function casts(): array
    {
        return ['completion_snapshot_ciphertext' => 'encrypted:array', 'submitted_at' => 'immutable_datetime', 'submission_version' => 'integer', 'task_version' => 'integer'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TaskSubmissionDocument::class, 'submission_id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(TaskReview::class, 'submission_id');
    }

    /** The history event recorded when the system completed this submission because it involved no payment. */
    public function autoVerification(): HasOne
    {
        return $this->hasOne(TaskStatusHistory::class, 'submission_id')->where('event_code', 'auto_verified');
    }

    /** Verification status for display: the Admin decision, Completed when verified automatically, or pending. */
    public function verificationLabel(): string
    {
        return match (true) {
            $this->review !== null => ucfirst($this->review->decision),
            $this->autoVerification !== null => 'Completed',
            default => 'Pending verification',
        };
    }
}
