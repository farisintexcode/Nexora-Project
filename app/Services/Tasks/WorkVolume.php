<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/** Dashboard volume of tasks created and tasks approved per local day or month. */
class WorkVolume
{
    /** @var array{daily: list<int>, monthly: list<int>} */
    public const RANGES = ['daily' => [7, 14, 30], 'monthly' => [3, 6, 12]];

    /**
     * @return array{buckets: Collection<int, array{label: string, date: string, created: int, approved: int}>, created: int, approved: int}|null
     */
    public function forUser(User $user, string $period, int $range): ?array
    {
        if (! Gate::forUser($user)->allows('viewAny', Task::class)) {
            return null;
        }

        $monthly = $period === 'monthly';
        $timezone = config('dcms.business_timezone');
        $now = now()->timezone($timezone);
        $start = $monthly ? $now->copy()->startOfMonth()->subMonthsNoOverflow($range - 1) : $now->copy()->startOfDay()->subDays($range - 1);
        $bucketFormat = $monthly ? 'YYYY-MM' : 'YYYY-MM-DD';
        $count = fn (Builder $query, string $column): Collection => $query->where($column, '>=', $start->copy()->utc())->where($column, '<=', now())
            ->selectRaw("to_char({$column} at time zone ?, '{$bucketFormat}') as bucket, count(*) as total", [$timezone])
            ->groupBy('bucket')->pluck('total', 'bucket');

        $created = $count(Task::query(), 'created_at');
        $approved = $count(Task::query()->where('work_status', 'completed')->where('review_status', 'approved'), 'completed_at');

        $buckets = collect(range(0, $range - 1))->map(function (int $offset) use ($monthly, $start, $range, $created, $approved): array {
            $date = $monthly ? $start->copy()->addMonthsNoOverflow($offset) : $start->copy()->addDays($offset);
            $key = $date->format($monthly ? 'Y-m' : 'Y-m-d');

            return [
                'label' => $date->format($monthly ? 'M' : ($range === 7 ? 'D' : 'j')),
                'date' => $date->format($monthly ? 'M Y' : 'd M Y'),
                'created' => (int) ($created[$key] ?? 0),
                'approved' => (int) ($approved[$key] ?? 0),
            ];
        });

        return [
            'buckets' => $buckets,
            'created' => $buckets->sum('created'),
            'approved' => $buckets->sum('approved'),
        ];
    }
}
