<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\AttendanceScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AttendanceSchedule extends Model
{
    /** @use HasFactory<AttendanceScheduleFactory> */
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['lock_version' => 0];

    protected function casts(): array
    {
        return ['service_date' => 'immutable_date', 'scheduled_start_at' => 'immutable_datetime', 'scheduled_end_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'rider_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicy::class, 'policy_id');
    }

    public function attendance(): HasOne
    {
        return $this->hasOne(Attendance::class, 'schedule_id');
    }
}
