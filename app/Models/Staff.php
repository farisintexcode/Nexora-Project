<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Database.md staff; Rider is an eligibility attribute, not a separate profile. */
class Staff extends Model
{
    use HasFactory, HasPublicId;

    protected $table = 'staff';

    protected $fillable = ['staff_no', 'first_name', 'last_name', 'email', 'gender_code', 'position_code', 'appointment_date', 'phone_ciphertext', 'home_phone_ciphertext', 'address_ciphertext'];

    protected $hidden = ['phone_ciphertext', 'home_phone_ciphertext', 'address_ciphertext'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_rider' => 'boolean', 'appointment_date' => 'immutable_date', 'phone_ciphertext' => 'encrypted', 'home_phone_ciphertext' => 'encrypted', 'address_ciphertext' => 'encrypted'];
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function attendanceSchedules(): HasMany
    {
        return $this->hasMany(AttendanceSchedule::class, 'rider_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'rider_id');
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(get: fn (): string => trim($this->first_name.' '.$this->last_name));
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'position_code', 'code')->where('category', 'position');
    }

    public function workState(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'work_state_code', 'code')->where('category', 'state');
    }

    public function gender(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'gender_code', 'code')->where('category', 'gender');
    }

    public function revision(): string
    {
        $persisted = static::query()->findOrFail($this->id);

        return hash('sha256', json_encode([
            $persisted->getRawOriginal(),
            AuditLog::query()->where('module', 'M10')->where('entity_type', 'staff')->where('entity_id', $persisted->public_id)->max('id'),
        ], JSON_THROW_ON_ERROR));
    }
}
