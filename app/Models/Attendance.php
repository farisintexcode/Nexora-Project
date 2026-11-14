<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['lock_version' => 0, 'status' => 'needs_review', 'is_late' => false, 'late_minutes' => 0];

    protected function casts(): array
    {
        return ['service_date' => 'immutable_date', 'raw_clock_in_at' => 'immutable_datetime', 'raw_clock_out_at' => 'immutable_datetime', 'effective_clock_in_at' => 'immutable_datetime', 'effective_clock_out_at' => 'immutable_datetime', 'is_late' => 'boolean', 'worked_minutes' => 'integer', 'late_minutes' => 'integer', 'lock_version' => 'integer'];
    }

    /**
     * Shifts the Rider can still clock out of: no clock-out yet, and the service date is not covered by approved leave.
     * An unfinished clock-in on an approved leave day is superseded by the leave.
     *
     * @param  Builder<Attendance>  $query
     */
    public function scopeOpenShift(Builder $query): void
    {
        $query->whereNull('attendances.effective_clock_out_at')->whereNotExists(fn ($leave) => $leave->selectRaw('1')->from('leave_requests')
            ->whereColumn('leave_requests.rider_id', 'attendances.rider_id')->where('leave_requests.status', 'approved')
            ->whereColumn('leave_requests.start_date', '<=', 'attendances.service_date')->whereColumn('leave_requests.end_date', '>=', 'attendances.service_date'));
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'rider_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(AttendanceSchedule::class, 'schedule_id');
    }
}
