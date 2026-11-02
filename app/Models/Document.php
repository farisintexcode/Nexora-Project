<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** M04 / Database.md: versioned registry, private source documents and encrypted staging. */
class Document extends Model
{
    public const CLEARED_SCANS = ['clean', 'skipped'];

    use HasFactory, HasPublicId;
    use SoftDeletes;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['caption_ciphertext', 'bucket', 'object_key', 'version_id'];

    protected function casts(): array
    {
        return ['caption_ciphertext' => 'encrypted', 'retained_until' => 'immutable_datetime', 'captured_at' => 'immutable_datetime'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** M06 / UI23: stable display reference; original evidence bytes remain untouched. */
    /** Clean, or skipped where the environment does not require a scan. Production always scans. */
    public function isCleared(): bool
    {
        return in_array($this->scan_status, self::CLEARED_SCANS, true);
    }

    public function proofReference(): string
    {
        return 'IMG-'.$this->id.'-'.strtoupper(substr(str_replace('-', '', $this->public_id), -8));
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function submissionDocuments(): HasMany
    {
        return $this->hasMany(TaskSubmissionDocument::class);
    }
}
