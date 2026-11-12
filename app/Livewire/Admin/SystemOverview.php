<?php

namespace App\Livewire\Admin;

use App\Enums\AccountStatus;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** M01/M10/M12: System Admin home — accounts, access and system settings, never assignment or task work. */
#[Layout('layouts.admin')]
class SystemOverview extends Component
{
    /** Account events worth a System Admin's attention, with plain-language labels. */
    public const ACCOUNT_EVENTS = [
        'account_invitation_prepared' => 'Invitation sent',
        'staff_account_updated' => 'Account access changed',
        'account_unlocked' => 'Account unlocked',
        'account_password_reset_sent' => 'Password reset link sent',
        'account_password_reset_failed' => 'Password reset email failed',
        'bootstrap_admin' => 'System Admin account created',
    ];

    public function render(): View
    {
        Gate::authorize('roles.manage');
        $statusCounts = User::query()->selectRaw('status, count(*) AS total')->groupBy('status')->pluck('total', 'status');

        return view('livewire.admin.system-overview', [
            'statusCounts' => collect(AccountStatus::cases())->mapWithKeys(fn (AccountStatus $status): array => [$status->value => (int) ($statusCounts[$status->value] ?? 0)]),
            'roleCounts' => $this->roleCounts(),
            'lockedAccounts' => User::query()->where('status', AccountStatus::Locked)->with('staff:id,public_id')->orderBy('name')->limit(5)->get(['id', 'name', 'email', 'staff_id']),
            'staffWithoutLogin' => Staff::query()->where('is_active', true)->whereDoesntHave('user')->count(),
            'failedLogins' => AuditLog::query()->where('action', 'login_failed')->where('occurred_at', '>=', now()->subDay())->count(),
            'failedJobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
            'activity' => Gate::allows('audit.view') ? AuditLog::query()->whereIn('action', array_keys(self::ACCOUNT_EVENTS))->with('actor:id,name')->orderByDesc('id')->limit(8)->get() : collect(),
        ]);
    }

    /**
     * Accounts per system role; legacy "Admin" memberships count as the role their permissions represent.
     *
     * @return Collection<string, int>
     */
    private function roleCounts(): Collection
    {
        $labels = User::query()->whereHas('roles')->with(['roles.permissions', 'permissions'])->get(['id'])
            ->flatMap(fn (User $user): array => explode(', ', $user->systemRoleLabel()))
            ->countBy();

        return collect(config('dcms.staff_roles'))->mapWithKeys(fn (string $role): array => [$role => (int) ($labels[$role] ?? 0)]);
    }
}
