<?php

namespace App\Livewire\Admin\Attendance;

use App\Actions\Attendance\ConfigureAttendance;
use App\Models\Attendance;
use App\Models\AttendancePolicy;
use App\Models\PublicHoliday;
use App\Models\ReferenceValue;
use App\Services\Attendance\PublicHolidays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** M10/UI59: the policy every Rider follows, and Malaysian public holidays. */
#[Layout('layouts.admin')]
class Configuration extends Component
{
    public string $code = 'standard';

    public array $rules = [];

    public string $effectiveFrom = '';

    #[Url(as: 'year')]
    public string $holidayYear = '';

    #[Url(as: 'state')]
    public string $holidayStateFilter = '';

    #[Url(as: 'month')]
    public string $holidayMonth = '';

    #[Url(as: 'view')]
    public string $holidayView = 'calendar';

    public string $holidayDate = '';

    public string $holidayName = '';

    public string $holidayState = '';

    public function mount(): void
    {
        Gate::authorize('configure', Attendance::class);
        $today = now()->timezone(config('dcms.business_timezone'));
        $current = AttendancePolicy::defaultOn($today->toDateString());
        $this->code = $current->code ?? config('attendance.initial_code');
        $this->rules = $current->rules ?? config('attendance.initial_rules');
        $this->effectiveFrom = $current ? $today->copy()->addDay()->toDateString() : $today->toDateString();
        $this->holidayYear = $this->holidayYear ?: $today->format('Y');
        $this->holidayMonth = $this->holidayMonth ?: $today->format('n');
    }

    public function publish(ConfigureAttendance $configure): void
    {
        $policy = $configure->policy(auth()->user(), ['code' => $this->code, 'rules' => $this->rules, 'is_default' => true, 'effective_from' => $this->effectiveFrom]);
        session()->flash('status', "{$policy->code} version {$policy->version} published. It applies to every Rider from {$policy->effective_from->format('d M Y')}, every day, until you publish a new version.");
    }

    public function syncHolidays(PublicHolidays $holidays): void
    {
        $result = $holidays->sync(auth()->user(), (int) $this->holidayYear);
        session()->flash('status', "Malaysian holidays for {$this->holidayYear} updated: {$result['added']} new, {$result['updated']} changed, {$result['removed']} removed.");
    }

    public function addHoliday(PublicHolidays $holidays): void
    {
        $holiday = $holidays->add(auth()->user(), $this->holidayDate, $this->holidayName, $this->holidayState ?: null);
        $this->reset('holidayDate', 'holidayName', 'holidayState');
        $this->holidayYear = $holiday->holiday_date->format('Y');
        $this->holidayMonth = $holiday->holiday_date->format('n');
        session()->flash('status', 'Holiday added.');
    }

    /**
     * Turn one listed holiday (all of its state rows) on or off.
     *
     * @param  list<int>  $holidayIds
     */
    public function toggleHoliday(array $holidayIds, bool $active, PublicHolidays $holidays): void
    {
        foreach (PublicHoliday::query()->whereKey(array_slice(array_map('intval', $holidayIds), 0, 20))->pluck('id') as $holidayId) {
            $holidays->setActive(auth()->user(), $holidayId, $active);
        }
    }

    /** Move the holiday calendar by whole months, crossing into the next or previous year. */
    public function shiftMonth(int $step): void
    {
        $shown = CarbonImmutable::create($this->calendarYear(), $this->calendarMonth(), 1)->addMonthsNoOverflow(max(-12, min(12, $step)));
        if ($shown->year >= 2000 && $shown->year <= 2100) {
            $this->holidayYear = (string) $shown->year;
            $this->holidayMonth = (string) $shown->month;
        }
    }

    public function showThisMonth(): void
    {
        $today = now()->timezone(config('dcms.business_timezone'));
        $this->holidayYear = $today->format('Y');
        $this->holidayMonth = $today->format('n');
    }

    private function calendarYear(): int
    {
        return ctype_digit($this->holidayYear) && (int) $this->holidayYear >= 2000 && (int) $this->holidayYear <= 2100 ? (int) $this->holidayYear : (int) now()->timezone(config('dcms.business_timezone'))->format('Y');
    }

    private function calendarMonth(): int
    {
        return ctype_digit($this->holidayMonth) && (int) $this->holidayMonth >= 1 && (int) $this->holidayMonth <= 12 ? (int) $this->holidayMonth : (int) now()->timezone(config('dcms.business_timezone'))->format('n');
    }

    public function render(PublicHolidays $holidays): View
    {
        Gate::authorize('configure', Attendance::class);
        $today = now()->timezone(config('dcms.business_timezone'))->toDateString();
        $year = $this->calendarYear();
        $month = $this->calendarMonth();
        $states = ReferenceValue::query()->where('category', 'state')->where('is_active', true)->orderBy('label')->get();
        $stateFilter = $states->contains('code', $this->holidayStateFilter) ? $this->holidayStateFilter : '';

        $yearHolidays = PublicHoliday::query()->with('state')->whereYear('holiday_date', $year)
            ->when($stateFilter !== '', fn ($query) => $query->where(fn ($query) => $query->whereNull('state_code')->orWhere('state_code', $stateFilter)))
            ->orderBy('holiday_date')->orderBy('name')->get()
            ->groupBy(fn (PublicHoliday $holiday): string => $holiday->holiday_date->toDateString().'|'.$holiday->name)
            ->map(fn ($group): array => [
                'date' => $group->first()->holiday_date, 'name' => $group->first()->name, 'ids' => $group->pluck('id')->all(),
                'national' => $group->contains(fn (PublicHoliday $holiday): bool => $holiday->state_code === null),
                'states' => $group->map(fn (PublicHoliday $holiday): ?string => $holiday->state?->label)->filter()->sort()->values()->all(),
                'manual' => $group->contains('source', 'manual'), 'active' => $group->contains('is_active', true),
                'tentative' => $group->contains('is_tentative', true), 'replacement' => str_starts_with((string) $group->first()->external_id, 'replacement:'),
            ])->values();

        return view('livewire.admin.attendance.configuration', [
            'currentPolicy' => AttendancePolicy::defaultOn($today),
            'upcomingPolicies' => AttendancePolicy::query()->where('is_default', true)->where('is_active', true)->where('effective_from', '>', $today)->orderBy('effective_from')->orderBy('id')->get(),
            'today' => $today,
            'states' => $states,
            'holidayYearValue' => $year,
            'holidays' => $yearHolidays,
            'holidayMonthValue' => $month,
            'calendarWeeks' => $this->calendarWeeks($year, $month, $yearHolidays, $stateFilter, $today),
            'monthHolidays' => $yearHolidays->filter(fn (array $holiday): bool => $holiday['date']->month === $month)->values(),
            'syncConfigured' => $holidays->syncConfigured(),
        ]);
    }

    /**
     * Monday-first weeks covering one month, each day carrying its holidays and whether it is a weekend.
     *
     * @param  Collection<int, array{date: CarbonImmutable}>  $holidays
     * @return list<list<array{date: CarbonImmutable, inMonth: bool, isToday: bool, isWeekend: bool, holidays: list<array<string, mixed>>}>>
     */
    private function calendarWeeks(int $year, int $month, Collection $holidays, string $stateFilter, string $today): array
    {
        $weekend = in_array($stateFilter, config('attendance.friday_weekend_states', []), true) ? [5, 6] : [6, 7];
        $byDate = $holidays->groupBy(fn (array $holiday): string => $holiday['date']->toDateString());
        $first = CarbonImmutable::create($year, $month, 1);
        $day = $first->startOfWeek(CarbonImmutable::MONDAY);
        $last = $first->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);
        $days = [];
        while ($day->lessThanOrEqualTo($last)) {
            $days[] = [
                'date' => $day, 'inMonth' => $day->month === $month, 'isToday' => $day->toDateString() === $today,
                'isWeekend' => in_array($day->isoWeekday(), $weekend, true), 'holidays' => $byDate->get($day->toDateString(), collect())->values()->all(),
            ];
            $day = $day->addDay();
        }

        return array_chunk($days, 7);
    }
}
