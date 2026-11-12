<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/** Database.md users; Module.md M01. Privileged fields are set only by trusted actions. */
class User extends Authenticatable
{
    use HasFactory, HasPublicId, HasRoles, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['status' => AccountStatus::class, 'email_verified_at' => 'immutable_datetime', 'last_login_at' => 'immutable_datetime', 'password' => 'hashed'];
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => mb_strtolower(trim($value)));
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /** Presentation only: retain legacy memberships and authorize exclusively through permissions. */
    public function systemRoleLabel(): string
    {
        return $this->getRoleNames()->map(fn (string $role): string => $role === 'Admin'
            ? ($this->can('roles.manage') ? 'System Admin' : 'Administrative')
            : $role)->unique()->join(', ');
    }

    /** System Admins without task access get the account and settings workspace instead of the operations dashboard. */
    public function usesSystemAdminWorkspace(): bool
    {
        return $this->can('roles.manage') && ! $this->can('tasks.view');
    }

    /** Short name for the header: the linked staff member's first name, else the account name (accounts without a staff record). */
    public function firstName(): string
    {
        $firstName = $this->staff_id ? $this->staff()->value('first_name') : null;

        return filled($firstName) ? $firstName : $this->name;
    }

    public function profilePhoto(): HasOne
    {
        return $this->hasOne(Document::class, 'owner_id')->ofMany(['id' => 'max'], function (Builder $query): void {
            $query->where('owner_type', 'user')->where('document_type_code', 'avatar')->where('scan_status', 'clean');
        });
    }

    public function canManageOwnProfilePhoto(): bool
    {
        return $this->isActive() && ($this->can('admin.access') || $this->canUseRiderInterface());
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active && ($this->staff_id === null || $this->staff()->where('is_active', true)->exists());
    }

    public function canUseRiderInterface(): bool
    {
        return $this->isActive() && $this->can('rider.access') && $this->staff()->where('is_active', true)->where('is_rider', true)->exists();
    }
}
