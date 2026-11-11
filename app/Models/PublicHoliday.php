<?php

namespace App\Models;

use Database\Factories\PublicHolidayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Malaysian public holiday: national when state_code is null, otherwise for one state only. */
class PublicHoliday extends Model
{
    /** @use HasFactory<PublicHolidayFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['holiday_date' => 'immutable_date', 'is_active' => 'boolean', 'is_tentative' => 'boolean'];
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'state_code', 'code')->where('category', 'state');
    }
}
