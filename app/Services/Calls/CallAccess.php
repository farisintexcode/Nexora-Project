<?php

namespace App\Services\Calls;

use App\Models\AccountParty;
use App\Models\CollectionActivity;
use App\Models\Debtor;
use App\Models\FollowUp;
use App\Models\Task;
use App\Models\User;
use App\Services\Customers\CustomerSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** M11/UI60: current permissions and portfolio scope apply to every query. */
class CallAccess
{
    public function __construct(private CustomerSearch $search) {}

    public function allowed(User $actor): bool
    {
        return $actor->isActive() && $actor->can('admin.access') && $actor->can('calls.record') && $actor->can('customers.view');
    }

    public function customers(User $actor): Builder
    {
        return Debtor::query();
    }

    public function customer(User $actor, int $id): Debtor
    {
        abort_unless($this->allowed($actor), 403);

        return $this->customers($actor)->findOrFail($id);
    }

    /** Follow-ups on customers in scope; visit follow-ups only for actors who may monitor tasks. */
    public function followUps(User $actor): Builder
    {
        return FollowUp::query()->whereIn('customer_id', $this->customers($actor)->select('debtors.id'))->where(function ($q) use ($actor): void {
            $q->whereHas('activity', fn ($q) => $q->where('activity_type_code', 'call'));
            if (Gate::forUser($actor)->allows('viewAny', Task::class)) {
                $q->orWhereHas('activity', fn ($q) => $q->where('activity_type_code', 'visit'));
            }
        });
    }

    public function activities(User $actor, Debtor $customer): Builder
    {
        $query = CollectionActivity::query()->where('customer_id', $customer->id);

        return $query->where(function ($q) use ($actor): void {
            $q->where('activity_type_code', 'call');
            if (Gate::forUser($actor)->allows('viewAny', Task::class)) {
                $q->orWhere(fn ($q) => $q->where('activity_type_code', 'visit'));
            }
        });
    }

    public function search(User $actor, array $filters): Builder
    {
        $q = $this->customers($actor);
        $rider = $filters['rider'] ?? '';
        if ($rider !== '') {
            if (Str::isUuid($rider) && Gate::forUser($actor)->allows('viewAny', Task::class)) {
                $q->whereIn('id', Task::query()->select('customer_id')->whereHas('currentAllocation.collector', fn ($b) => $b->where('public_id', $rider)));
            } else {
                $q->whereRaw('false');
            }
        }
        $term = trim($filters['query'] ?? '');
        $kind = $filters['searchBy'] ?? 'reference';
        if (! in_array($kind, ['reference', 'name', 'contact', 'account', 'task'], true)) {
            return $q->whereRaw('false');
        }
        if (mb_strlen($term) > 200) {
            return $q->whereRaw('false');
        }
        if ($term === '') {
            return $q;
        }
        if ($kind === 'account') {
            $q->whereIn('id', AccountParty::query()->select('debtor_id')->whereNull('valid_to')->whereHas('account', fn ($b) => $b->where('account_number', $term)));
        } elseif ($kind === 'task') {
            if (Str::isUuid($term) && Gate::forUser($actor)->allows('viewAny', Task::class)) {
                $q->whereIn('id', Task::query()->select('customer_id')->where('public_id', $term));
            } else {
                $q->whereRaw('false');
            }
        } else {
            $this->search->apply($q, $kind, $term);
        }

        return $q;
    }
}
