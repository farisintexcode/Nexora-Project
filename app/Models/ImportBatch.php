<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** M04 / Database.md: versioned registry, private source documents and encrypted staging. */
class ImportBatch extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'mapping_confirmed_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime'];
    }

    /** Uploaded Excel files that created tasks in one assignment, newest first. */
    public function scopeTaskFilesFor(Builder $query, int $assignmentId): void
    {
        $query->with('source')->where('assignment_batch_id', $assignmentId)->where('kind', 'task')->where('processed_count', '>', 0)->latest('id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ImportTemplate::class, 'template_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(ImportColumnMapping::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(AssignmentBatch::class, 'assignment_batch_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
