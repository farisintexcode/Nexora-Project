<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** M04 / Database.md: versioned registry, private source documents and encrypted staging. */
class ImportColumnMapping extends Model
{
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['matched_rule_snapshot' => 'array', 'is_ignored' => 'boolean', 'confirmed_at' => 'immutable_datetime', 'confidence' => 'decimal:4'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ImportField::class, 'field_id');
    }
}
