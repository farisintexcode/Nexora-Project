<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** M10 / leave_requests: immutable own-staff application and independent decision. */
class LeaveRequest extends Model
{
    use HasFactory, HasPublicId;

    protected $hidden = ['reason', 'reviewer_remarks'];

    protected function casts(): array
    {
        return ['start_date' => 'immutable_date', 'end_date' => 'immutable_date', 'submitted_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'reason' => 'encrypted', 'reviewer_remarks' => 'encrypted', 'type_snapshot' => 'array', 'calendar_snapshot' => 'array', 'total_days' => 'integer', 'lock_version' => 'integer'];
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'rider_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'supporting_document_id');
    }

    public function schedules(): BelongsToMany
    {
        return $this->belongsToMany(AttendanceSchedule::class, 'leave_request_days', 'leave_request_id', 'schedule_id')->withPivot('service_date', 'charged');
    }
}
