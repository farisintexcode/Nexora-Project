<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Malaysian national and state public holidays from the Malaysia Calendar API (JPM gazette data),
 * plus replacement days (cuti ganti) and holidays an administrator adds by hand.
 */
class PublicHolidays
{
    /** @var list<string> DCMS state codes, matching the 'state' reference values. */
    public const STATES = ['johor', 'kedah', 'kelantan', 'melaka', 'negeri_sembilan', 'pahang', 'pulau_pinang', 'perak', 'perlis', 'selangor', 'terengganu', 'sabah', 'sarawak', 'kuala_lumpur', 'labuan', 'putrajaya'];

    public function __construct(private AuditWriter $audit) {}

    public function syncConfigured(): bool
    {
        return filled(config('services.malaysia_calendar.url'));
    }

    /**
     * Download one year of holidays. Each holiday keeps the administrator's on/off choice; a moved tentative date
     * updates in place, and holidays the calendar no longer lists are removed.
     *
     * @return array{added: int, updated: int, removed: int, total: int}
     */
    public function sync(?User $actor, int $year): array
    {
        if ($actor) {
            Gate::forUser($actor->fresh())->authorize('configure', Attendance::class);
        }
        Validator::make(['year' => $year], ['year' => ['required', 'integer', 'between:2000,2100']])->validate();
        if (! $this->syncConfigured()) {
            throw ValidationException::withMessages(['holidays' => 'Online holiday updates are not set up. Set MALAYSIA_CALENDAR_API_URL in the .env file.']);
        }

        $base = rtrim((string) config('services.malaysia_calendar.url'), '/');
        $client = fn () => Http::timeout(20)->retry(2, 500, throw: false)->acceptJson()->withUserAgent(config('app.name').' attendance');
        try {
            $holidayResponse = $client()->get($base.'/holidays', ['year' => $year]);
            $stateResponse = $client()->get($base.'/states');
        } catch (ConnectionException $exception) {
            report($exception);

            throw ValidationException::withMessages(['holidays' => 'The online holiday calendar could not be reached. Try again later, or add holidays by hand.']);
        }
        $holidays = $holidayResponse->json('data');
        $states = $stateResponse->json('data');
        if ($holidayResponse->failed() || $stateResponse->failed() || ! is_array($holidays) || ! is_array($states)) {
            throw ValidationException::withMessages(['holidays' => 'The online holiday calendar could not be reached. Try again later, or add holidays by hand.']);
        }

        $rows = collect($holidays)->filter(fn (mixed $holiday): bool => is_array($holiday))->flatMap(fn (array $holiday): array => $this->rows($holiday))->values();
        $rows = $rows->concat($this->replacements($rows, $this->weekends($states)))
            ->unique(fn (array $row): string => $row['external_id'].'|'.$row['state_code'])->values();

        $result = DB::transaction(function () use ($actor, $rows, $year): array {
            $counts = ['added' => 0, 'updated' => 0, 'removed' => 0];
            $kept = [];
            foreach ($rows as $row) {
                $scope = fn ($query) => $row['state_code'] === null ? $query->whereNull('state_code') : $query->where('state_code', $row['state_code']);
                $holiday = PublicHoliday::query()->where('external_id', $row['external_id'])->where($scope)->first()
                    ?? PublicHoliday::query()->where('holiday_date', $row['holiday_date'])->where('name', $row['name'])->where($scope)->first();
                $isNew = $holiday === null;
                $holiday ??= new PublicHoliday;
                $holiday->forceFill([...Arr::except($row, 'one_off'), 'source' => 'online', 'is_active' => $isNew ? true : $holiday->is_active, 'created_by' => $holiday->created_by ?? $actor?->id]);
                if ($isNew || $holiday->isDirty()) {
                    $holiday->save();
                    $counts[$isNew ? 'added' : 'updated']++;
                }
                $kept[] = $holiday->id;
            }
            $counts['removed'] = PublicHoliday::query()->where('source', 'online')->whereNotIn('id', $kept)
                ->where(fn ($query) => $query->where('external_id', 'like', $year.'-%')->orWhere('external_id', 'like', 'replacement:'.$year.'-%'))->delete();
            $this->audit->record($actor, 'attendance.holidays_synced', (string) $year, $counts, module: 'M10', entityType: 'public_holiday');

            return $counts;
        });

        return [...$result, 'total' => $rows->count()];
    }

    /**
     * One API holiday as a national row or one row per state.
     *
     * @param  array<string, mixed>  $holiday
     * @return list<array{external_id: string, holiday_date: string, name: string, state_code: ?string, is_tentative: bool, one_off: bool}>
     */
    public function rows(array $holiday): array
    {
        $date = (string) ($holiday['date'] ?? '');
        $name = Str::limit(trim((string) (data_get($holiday, 'name.en') ?: data_get($holiday, 'name.ms') ?: '')), 150, '');
        $id = Str::limit((string) ($holiday['id'] ?? $date.'-'.Str::slug($name)), 110, '');
        if (($holiday['isPublicHoliday'] ?? true) !== true || $name === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return [];
        }
        $base = ['external_id' => $id, 'holiday_date' => $date, 'name' => $name, 'is_tentative' => ($holiday['status'] ?? 'confirmed') !== 'confirmed', 'one_off' => ($holiday['type'] ?? null) === 'adhoc'];
        $states = (array) ($holiday['states'] ?? ['*']);
        if (in_array('*', $states, true)) {
            return [[...$base, 'state_code' => null]];
        }

        return collect($states)->map(fn (mixed $state): ?string => is_string($state) ? $this->stateCode($state) : null)->filter()->unique()
            ->map(fn (string $code): array => [...$base, 'state_code' => $code])->values()->all();
    }

    /** Map an API state code or alias ("wp-putrajaya", "pulau-pinang", "KL") to a DCMS state code. */
    public function stateCode(string $value): ?string
    {
        $value = str_replace(['-', ' '], '_', mb_strtolower(trim($value)));
        $value = preg_replace('/^(wp_|federal_territory_of_|wilayah_persekutuan_)/', '', $value) ?? $value;
        $value = ['kl' => 'kuala_lumpur', 'penang' => 'pulau_pinang', 'pinang' => 'pulau_pinang', 'malacca' => 'melaka'][$value] ?? $value;

        return in_array($value, self::STATES, true) ? $value : null;
    }

    /**
     * Weekend history per state from the API: weekday numbers use 0 = Sunday.
     *
     * @param  array<int, mixed>  $states
     * @return array<string, list<array{from: string, to: ?string, weekend: list<int>, replaced: int}>>
     */
    public function weekends(array $states): array
    {
        $rules = [];
        foreach ($states as $state) {
            $code = is_array($state) ? $this->stateCode((string) ($state['code'] ?? '')) : null;
            if ($code === null) {
                continue;
            }
            foreach ((array) ($state['weekendHistory'] ?? []) as $period) {
                if (is_array($period) && isset($period['from'], $period['replacedWeekday'])) {
                    $rules[$code][] = ['from' => (string) $period['from'], 'to' => $period['to'] ?? null, 'weekend' => array_map('intval', (array) ($period['weekendDays'] ?? [])), 'replaced' => (int) $period['replacedWeekday']];
                }
            }
        }

        return $rules;
    }

    /**
     * Cuti ganti: a holiday on a state's replaced weekday (Sunday in most states) moves to the next day that is
     * neither a weekend nor already a holiday in that state. One-off extra holidays are announced with their own dates.
     *
     * @param  Collection<int, array{external_id: string, holiday_date: string, name: string, state_code: ?string, is_tentative: bool}>  $rows
     * @param  array<string, list<array{from: string, to: ?string, weekend: list<int>, replaced: int}>>  $weekends
     * @return list<array{external_id: string, holiday_date: string, name: string, state_code: string, is_tentative: bool}>
     */
    public function replacements(Collection $rows, array $weekends): array
    {
        $rule = function (string $state, string $date) use ($weekends): ?array {
            foreach ($weekends[$state] ?? [] as $period) {
                if ($period['from'] <= $date && ($period['to'] === null || $period['to'] >= $date)) {
                    return $period;
                }
            }

            return null;
        };
        $taken = [];
        foreach ($rows as $row) {
            foreach ($row['state_code'] === null ? array_keys($weekends) : [$row['state_code']] as $state) {
                $taken[$state][$row['holiday_date']] = true;
            }
        }

        $replacements = [];
        foreach ($rows->where('one_off', false)->sortBy('holiday_date') as $row) {
            $date = CarbonImmutable::parse($row['holiday_date']);
            foreach ($row['state_code'] === null ? array_keys($weekends) : [$row['state_code']] as $state) {
                $period = $rule($state, $row['holiday_date']);
                if ($period === null || $date->dayOfWeek !== $period['replaced']) {
                    continue;
                }
                $next = $date->addDay();
                for ($guard = 0; $guard < 14; $guard++, $next = $next->addDay()) {
                    $nextRule = $rule($state, $next->toDateString()) ?? $period;
                    if (! in_array($next->dayOfWeek, $nextRule['weekend'], true) && ! isset($taken[$state][$next->toDateString()])) {
                        break;
                    }
                }
                $taken[$state][$next->toDateString()] = true;
                $replacements[] = ['external_id' => Str::limit('replacement:'.$row['external_id'], 120, ''), 'holiday_date' => $next->toDateString(), 'name' => Str::limit($row['name'].' (replacement holiday)', 150, ''), 'state_code' => $state, 'is_tentative' => $row['is_tentative'], 'one_off' => false];
            }
        }

        return $replacements;
    }

    public function between(string $from, string $until, ?string $stateCode): Collection
    {
        return PublicHoliday::query()->where('is_active', true)->whereBetween('holiday_date', [$from, $until])
            ->where(fn ($query) => $query->whereNull('state_code')->when($stateCode, fn ($query) => $query->orWhere('state_code', $stateCode)))
            ->orderBy('holiday_date')->orderBy('name')->get()
            ->groupBy(fn (PublicHoliday $holiday): string => $holiday->holiday_date->toDateString())
            ->map(fn (Collection $holidays): string => $holidays->pluck('name')->unique()->implode(' / '));
    }

    public function add(User $actor, string $date, string $name, ?string $stateCode): PublicHoliday
    {
        Gate::forUser($actor->fresh())->authorize('configure', Attendance::class);
        $data = Validator::make(['date' => $date, 'name' => trim($name), 'state' => $stateCode ?: null], [
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:150', 'regex:/\S/'],
            'state' => ['nullable', 'string', Rule::exists('reference_values', 'code')->where('category', 'state')],
        ])->validate();

        return DB::transaction(function () use ($actor, $data): PublicHoliday {
            $holiday = PublicHoliday::query()->where('holiday_date', $data['date'])->where('name', $data['name'])
                ->where(fn ($query) => $data['state'] === null ? $query->whereNull('state_code') : $query->where('state_code', $data['state']))->first() ?? new PublicHoliday;
            $holiday->forceFill(['holiday_date' => $data['date'], 'name' => $data['name'], 'state_code' => $data['state'], 'source' => $holiday->source ?? 'manual', 'is_active' => true, 'created_by' => $holiday->created_by ?? $actor->id])->save();
            $this->audit->record($actor, 'attendance.holiday_added', (string) $holiday->id, ['date' => $data['date'], 'state' => $data['state']], module: 'M10', entityType: 'public_holiday');

            return $holiday;
        });
    }

    public function setActive(User $actor, int $holidayId, bool $active): void
    {
        Gate::forUser($actor->fresh())->authorize('configure', Attendance::class);
        DB::transaction(function () use ($actor, $holidayId, $active): void {
            $holiday = PublicHoliday::query()->lockForUpdate()->findOrFail($holidayId);
            $holiday->forceFill(['is_active' => $active])->save();
            $this->audit->record($actor, $active ? 'attendance.holiday_enabled' : 'attendance.holiday_disabled', (string) $holiday->id, ['date' => $holiday->holiday_date->toDateString()], module: 'M10', entityType: 'public_holiday');
        });
    }
}
