<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** M04 / Database.md: versioned registry, private source documents and encrypted staging. */
class ImportBatch extends Model
{
    use HasFactory, HasPublicId, SoftDeletes;

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

    /**
     * Uploaded Excel files that have tasks in one assignment: uploaded into it, or whose unassigned tasks were moved into it.
     */
    public function scopeUsedByAssignment(Builder $query, int $assignmentId): void
    {
        $query->where(fn (Builder $query) => $query->where('import_batches.assignment_batch_id', $assignmentId)
            ->orWhereExists(self::tasksInFile()->where('tasks.assignment_batch_id', $assignmentId)->toBase()));
    }

    /**
     * Excel files offered when giving out tasks in an active assignment: its own files, plus files of archived assignments
     * of the same type whose unassigned tasks can still move into it. Newest first.
     */
    public function scopeAssignableFor(Builder $query, int $assignmentId): void
    {
        $query->with(['source', 'assignment'])->where('kind', 'task')->where('processed_count', '>', 0)
            ->where(fn (Builder $query) => $query->usedByAssignment($assignmentId)
                ->orWhereExists(self::tasksInFile()->movableInto($assignmentId)->toBase()))
            ->latest('id');
    }

    /**
     * Assignments holding this file's tasks: the one it was uploaded into, then any its unassigned tasks moved into.
     *
     * @return EloquentCollection<int, AssignmentBatch>
     */
    public function taskAssignments(): EloquentCollection
    {
        return once(fn (): EloquentCollection => AssignmentBatch::query()->whereKey($this->assignment_batch_id)
            ->orWhereIn('id', Task::query()->fromImport($this->id)->select('assignment_batch_id'))->orderBy('id')->get());
    }

    /** Tasks of the outer import_batches row, for correlated subqueries. */
    private static function tasksInFile(): Builder
    {
        return Task::query()->selectRaw('1')->where(fn (Builder $query) => $query->whereColumn('tasks.import_batch_id', 'import_batches.id')
            ->orWhereIn('tasks.source_import_row_id', ImportRow::query()->select('import_rows.id')->whereColumn('import_rows.import_batch_id', 'import_batches.id')));
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
