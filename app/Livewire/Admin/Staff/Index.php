<?php

namespace App\Livewire\Admin\Staff;

use App\Actions\Staff\SaveStaff;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** M10/UI30. Personnel access is explicit and independent of customer portfolio scopes. */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $kind = 'rider';

    #[Url]
    public string $status = '';

    #[Url]
    public string $role = '';

    #[Url]
    public string $accountStatus = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public string $sort = 'staff_no';

    #[Url]
    public string $perPage = '25';

    public string $query = '';

    public string $reason = '';

    public function changeAvailability(string $publicId, bool $active, SaveStaff $save): void
    {
        DB::transaction(function () use ($publicId, $active, $save): void {
            DB::select("select pg_advisory_xact_lock(hashtext('dcms.staff.availability'))");
            $staff = Staff::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $staff);
            $data = $staff->only(['staff_no', 'first_name', 'last_name', 'email', 'gender_code', 'position_code', 'is_rider']);
            foreach (['phone', 'home_phone', 'address'] as $field) {
                $data[$field] = $staff->{$field.'_ciphertext'};
            }
            $save->handle(Auth::user(), [...$data, 'is_active' => $active, 'reason' => $this->reason], $staff, $staff->revision());
        }, 3);
        $this->reason = '';
        session()->flash('status', 'Staff availability updated. Account activation still requires password setup.');
    }

    public function mount(): void
    {
        $this->query = session('staff_search.'.Auth::id(), '');
    }

    public function updated(string $property): void
    {
        $this->resetPage();
        if ($property === 'query') {
            session()->put('staff_search.'.Auth::id(), mb_substr($this->query, 0, 200));
        }
    }

    public function resetFilters(): void
    {
        $this->reset('kind', 'status', 'role', 'accountStatus', 'from', 'until', 'sort', 'perPage', 'query');
        session()->forget('staff_search.'.Auth::id());
        $this->resetPage();
    }

    public function render(): View
    {
        Gate::authorize('viewAny', Staff::class);
        $query = Staff::query()->with('user.roles');
        if ($this->role !== '') {
            if (in_array($this->role, config('dcms.staff_roles'), true)) {
                $query->whereHas('user', function ($q): void {
                    $q->where(function ($q): void {
                        $q->whereHas('roles', fn ($q) => $q->where('name', $this->role));
                        if ($this->role !== 'Rider') {
                            $q->orWhere(function ($q): void {
                                $q->role('Admin');
                                $this->role === 'System Admin' ? $q->permission('roles.manage') : $q->withoutPermission('roles.manage');
                            });
                        }
                    });
                });
            } else {
                $query->whereRaw('false');
            }
        }
        if ($this->accountStatus === 'none') {
            $query->whereDoesntHave('user');
        } elseif ($this->accountStatus !== '') {
            $query->whereHas('user', fn ($q) => $q->where('status', $this->accountStatus));
        }
        if (in_array($this->kind, ['rider', 'office'], true)) {
            $query->where('is_rider', $this->kind === 'rider');
        } elseif ($this->kind !== 'all') {
            $query->whereRaw('false');
        }
        if ($this->status !== '') {
            in_array($this->status, ['active', 'inactive'], true) ? $query->where('is_active', $this->status === 'active') : $query->whereRaw('false');
        }
        $dates = Validator::make(['from' => $this->from ?: null, 'until' => $this->until ?: null], ['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', ...($this->from !== '' ? ['after_or_equal:from'] : [])]]);
        if ($dates->fails()) {
            $query->whereRaw('false');
        } else {
            $query->when($this->from !== '', fn ($q) => $q->where('created_at', '>=', CarbonImmutable::parse($this->from, 'Asia/Kuala_Lumpur')->startOfDay()->utc()))
                ->when($this->until !== '', fn ($q) => $q->where('created_at', '<', CarbonImmutable::parse($this->until, 'Asia/Kuala_Lumpur')->addDay()->startOfDay()->utc()));
        }
        $term = trim($this->query);
        if (mb_strlen($term) > 200) {
            $query->whereRaw('false');
        } elseif ($term !== '') {
            $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $query->where(fn ($q) => $q->where('staff_no', 'ilike', $prefix)->orWhereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$prefix])->orWhereRaw('lower(email) = ?', [mb_strtolower($term)]));
        }
        match ($this->sort) {
            'name' => $query->orderBy('first_name')->orderBy('last_name')->orderBy('id'),
            'created' => $query->orderByDesc('created_at')->orderByDesc('id'),
            default => $query->orderBy('staff_no')->orderBy('id'),
        };
        $size = in_array($this->perPage, ['25', '50', '100'], true) ? (int) $this->perPage : 25;

        return view('livewire.admin.staff.index', [
            'staffMembers' => $query->paginate($size, ['id', 'public_id', 'staff_no', 'first_name', 'last_name', 'email', 'created_at', 'position_code', 'is_active', 'is_rider']),
            'filterError' => $dates->fails() ? $dates->errors()->first() : null,
        ]);
    }
}
