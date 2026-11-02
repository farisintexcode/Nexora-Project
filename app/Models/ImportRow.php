<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** M04 / Database.md: versioned registry, private source documents and encrypted staging. */
class ImportRow extends Model
{
    protected $hidden = ['raw_payload_ciphertext', 'normalized_payload_ciphertext'];

    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['raw_payload_ciphertext' => 'encrypted:array', 'normalized_payload_ciphertext' => 'encrypted:array', 'validation_errors' => 'array', 'processed_at' => 'immutable_datetime'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function assignmentBatch(): BelongsTo
    {
        return $this->belongsTo(AssignmentBatch::class, 'target_assignment_batch_id');
    }
}
