<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** M10 / leave_types: explicit organization rules; no seeded entitlements. */
class LeaveType extends Model
{
    use HasFactory, HasPublicId;

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'requires_document' => 'boolean', 'allow_past_dates' => 'boolean', 'allow_pending_cancel' => 'boolean', 'max_calendar_days' => 'integer', 'lock_version' => 'integer'];
    }
}
