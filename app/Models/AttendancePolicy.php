<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\AttendancePolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendancePolicy extends Model
{
    /** @use HasFactory<AttendancePolicyFactory> */
    use HasFactory, HasPublicId;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'attendance_policies';

    protected function casts(): array
    {
        return ['rules' => 'array', 'is_active' => 'boolean', 'approved_at' => 'immutable_datetime', 'is_default' => 'boolean', 'effective_from' => 'immutable_date'];
    }

    /** The default policy version applying to every Rider on a business date, if any. */
    public static function defaultOn(string $date): ?self
    {
        return static::query()->where('is_default', true)->where('is_active', true)->where('effective_from', '<=', $date)->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(AttendanceSchedule::class, 'policy_id');
    }
}
