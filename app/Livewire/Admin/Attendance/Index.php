<?php

namespace App\Livewire\Admin\Attendance;

use App\Models\Attendance;
use App\Models\PublicHoliday;
use App\Models\ReferenceValue;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\AttendanceRegister;
use App\Services\Attendance\AttendanceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** M10/UI33, Database.md schedules/punches/adjustments, Plan.md attendance. */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public const VIEWS = ['calendar', 'records'];

    /** Which riders count in each group of the day board, in display order. */
    public const DAY_GROUPS = [
        'working' => 'Clocked in',
        'done' => 'Clocked out',
        'leave' => 'On leave',
        'waiting' => 'Not clocked in',
        'off' => 'Rest day / holiday',
    ];

    /** Rider states shown on the month calendar, in the order they are listed in a day. */
    public const CALENDAR_STATES = [
        'absent' => 'Absent',
        'missing' => 'Missing clock-out',
        'working' => 'Clocked in',
        'done' => 'Clocked out',
        'leave' => 'On leave',
        'requested' => 'Leave requested',
    ];

    #[Url]
    public string $view = 'calendar';

    #[Url]
    public string $day = '';

    #[Url]
    public string $month = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $rider = '';

    public string $search = '';

    /** Calendar day list: one of DAY_GROUPS, or 'all'. */
    #[Url(as: 'show')]
    public string $dayGroup = 'all';

    /** Calendar day list: rider name or staff number. */
    public string $daySearch = '';

    public function mount(): void
    {
        if (! request()->has('view') && request()->hasAny(['from', 'until', 'status'])) {
            $this->view = 'records';
        }
        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'calendar';
        }
        if ($chosen = $this->validDate($this->day)) {
            $this->month = $chosen->format('Y-m');
        }
        $this->search = (string) session('attendance.filters.'.auth()->id().'.search', '');
        $this->from = $this->from ?: $this->today()->startOfMonth()->toDateString();
        $this->until = $this->until ?: $this->today()->toDateString();
    }

    public function updated(): void
    {
        $this->resetPage();
        $this->resetPage('dayPage');
    }

    /** Narrow the calendar day list to one group; choosing the active group again shows everyone. */
    public function showGroup(string $group): void
    {
        $this->dayGroup = $group !== $this->dayGroup && array_key_exists($group, self::DAY_GROUPS) ? $group : 'all';
        $this->resetPage('dayPage');
    }

    public function resetFilters(): void
    {
        $this->reset('status', 'rider', 'search');
        $this->from = $this->today()->startOfMonth()->toDateString();
        $this->until = $this->today()->toDateString();
        $this->resetPage();
    }

    public function filters(): array
    {
        return $this->only(['from', 'until', 'status', 'rider', 'search']);
    }

    /** Filter by a summary card; selecting the active card again clears the status filter. */
    public function selectStatus(string $status): void
    {
        $this->status = $this->status === $status ? '' : $status;
        $this->resetPage();
    }

    /** Show a calendar date's details beside the month calendar. */
    public function selectDay(string $date): void
    {
        $selected = $this->validDate($date);
        if ($selected === null) {
            return;
        }
        $this->day = $selected->toDateString();
        $this->month = $selected->format('Y-m');
        $this->resetPage('dayPage');
    }

    /** Jump the calendar to a month chosen from the month tabs. */
    public function selectMonth(string $month): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $this->month = $month;
            $this->day = '';
            $this->resetPage('dayPage');
        }
    }

    public function shiftMonth(int $months): void
    {
        $this->month = $this->selectedMonth()->addMonths(max(-1, min(1, $months)))->format('Y-m');
        $this->day = '';
        $this->resetPage('dayPage');
    }

    public function render(AttendanceRegister $register, AttendanceAccess $access): View
    {
        Gate::authorize('viewAny', Attendance::class);
        $view = in_array($this->view, self::VIEWS, true) ? $this->view : 'calendar';
        $data = ['view' => $view, 'riders' => $access->riders(auth()->user())->orderBy('staff_no')->limit(200)->get(), 'asOf' => now()->toImmutable()];

        return view('livewire.admin.attendance.index', [...$data, ...match ($view) {
            'calendar' => $this->calendar($register, $access),
            default => $this->records($register, $data['asOf']),
        }]);
    }

    /**
     * Every rider's attendance on one day, grouped by DAY_GROUPS.
     *
     * @return array<string, Collection<int, object>>
     */
    private function dayGroups(AttendanceRegister $register, CarbonImmutable $day): array
    {
        $rows = $register->query(auth()->user(), ['from' => $day->toDateString(), 'until' => $day->toDateString()], asOf: now()->toImmutable())
            ->orderBy('rider_name')->get();
        $groups = array_map(fn () => collect(), self::DAY_GROUPS);
        foreach ($rows as $row) {
            $group = match (true) {
                $row->result_status === 'leave' => 'leave',
                $row->effective_clock_out_at !== null => 'done',
                $row->raw_clock_in_at !== null => 'working',
                in_array($row->day_type, ['day_off', 'public_holiday', 'cancelled'], true) => 'off',
                default => 'waiting',
            };
            $groups[$group]->push($row);
        }

        return $groups;
    }

    /** The calendar date shown beside the month: the chosen date when it is in that month, otherwise today or the 1st. */
    private function calendarDay(CarbonImmutable $month): CarbonImmutable
    {
        $chosen = $this->validDate($this->day);
        if ($chosen !== null && $chosen->format('Y-m') === $month->format('Y-m')) {
            return $chosen;
        }

        return $this->today()->format('Y-m') === $month->format('Y-m') ? $this->today() : $month->startOfMonth();
    }

    /** @return array{calendarMonth: CarbonImmutable, weeks: array<int, list<CarbonImmutable>>, days: Collection<string, Collection<int, object>>, holidays: Collection, selectedDay: CarbonImmutable, selectedGroups: array<string, Collection<int, object>>} */
    private function calendar(AttendanceRegister $register, AttendanceAccess $access): array
    {
        $month = $this->selectedMonth();
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();
        $order = array_flip(array_keys(self::CALENDAR_STATES));
        $days = $register->query(auth()->user(), ['from' => $start->toDateString(), 'until' => $end->toDateString()], asOf: now()->toImmutable())
            ->where(fn ($query) => $query->whereIn('result_status', ['leave', 'absent'])->orWhereNotNull('raw_clock_in_at')->orWhere('pending_leave', true))
            ->selectRaw('service_date::date AS day, rider_public_id, rider_name, staff_no, result_status, raw_clock_in_at, effective_clock_out_at, pending_leave')
            ->orderBy('rider_name')->get()
            ->map(function (object $row): object {
                $row->state = $this->calendarState($row);

                return $row;
            })
            ->groupBy(fn (object $row): string => (string) $row->day)
            ->map(fn (Collection $riders): Collection => $riders->sortBy(fn (object $row): int => $order[$row->state])->values());
        $gridDays = [];
        for ($cursor = $start->startOfWeek(CarbonImmutable::MONDAY); $cursor->lte($end->endOfWeek(CarbonImmutable::SUNDAY)); $cursor = $cursor->addDay()) {
            $gridDays[] = $cursor;
        }

        $selected = $this->calendarDay($month);
        $groups = $this->dayGroups($register, $selected);

        return ['calendarMonth' => $month, 'weeks' => array_chunk($gridDays, 7), 'days' => $days, 'holidays' => $this->holidays($start, $end, $access),
            'selectedDay' => $selected, 'selectedGroups' => $groups, 'dayRows' => $this->dayList($groups)];
    }

    /**
     * The selected day as one table: narrowed by the chosen group and search, riders needing attention first, 25 per page.
     *
     * @param  array<string, Collection<int, object>>  $groups
     * @return LengthAwarePaginator<int, object>
     */
    private function dayList(array $groups): LengthAwarePaginator
    {
        $search = mb_strtolower(trim($this->daySearch));
        $rows = collect($groups)
            ->flatMap(fn (Collection $rows, string $group): Collection => $rows->map(fn (object $row): object => $this->describeDayRow($row, $group)))
            ->when(array_key_exists($this->dayGroup, self::DAY_GROUPS), fn (Collection $rows) => $rows->where('group', $this->dayGroup))
            ->when($search !== '', fn (Collection $rows) => $rows->filter(fn (object $row): bool => str_contains(mb_strtolower($row->rider_name), $search) || str_starts_with(mb_strtolower((string) $row->staff_no), $search)))
            ->sortBy([['priority', 'asc'], ['rider_name', 'asc']])
            ->values();
        $page = $this->getPage('dayPage');

        return new LengthAwarePaginator($rows->forPage($page, 25)->values(), $rows->count(), 25, $page, ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'dayPage']);
    }

    /** Label one rider's day for the day table; lower priority numbers need attention sooner. */
    private function describeDayRow(object $row, string $group): object
    {
        [$priority, $label, $tone] = match (true) {
            $group === 'waiting' && $row->result_status === 'absent' => [0, 'Absent', 'danger'],
            $group === 'working' && $row->result_status === 'incomplete' => [1, 'Missing clock-out', 'warning'],
            $group === 'waiting' => [2, 'Not in yet', 'neutral'],
            $group === 'working' => [$row->is_late ? 3 : 4, 'Clocked in', 'brand'],
            $group === 'done' => [$row->is_late ? 3 : 5, 'Clocked out', 'success'],
            $group === 'leave' => [6, 'On leave', 'brand'],
            default => [7, AttendanceStatus::LABELS[$row->day_type] ?? 'Rest Day', 'neutral'],
        };
        $row->group = $group;
        $row->priority = $priority;
        $row->status_label = $label;
        $row->status_tone = $tone;

        return $row;
    }

    /** Pick the single calendar state a rider's day shows, most urgent first. */
    private function calendarState(object $row): string
    {
        return match (true) {
            $row->result_status === 'leave' => 'leave',
            $row->effective_clock_out_at !== null => 'done',
            $row->raw_clock_in_at !== null => $row->result_status === 'incomplete' ? 'missing' : 'working',
            $row->result_status === 'absent' => 'absent',
            default => 'requested',
        };
    }

    private function records(AttendanceRegister $register, CarbonImmutable $asOf): array
    {
        $error = null;
        try {
            $query = $register->query(auth()->user(), $this->filters(), asOf: $asOf);
            $summary = $register->query(auth()->user(), [...$this->filters(), 'status' => ''], asOf: $asOf);
            session()->put('attendance.filters.'.auth()->id(), $this->filters());
        } catch (ValidationException $e) {
            $error = $e->validator->errors()->first();
            $query = $register->query(auth()->user(), [])->whereRaw('false');
            $summary = clone $query;
        }
        $totals = $summary->selectRaw('result_status, count(*) as total')->groupBy('result_status')->pluck('total', 'result_status')->all();

        return ['rows' => $query->orderByDesc('service_date')->orderBy('staff_no')->orderBy('rider_public_id')->paginate(25), 'totals' => $totals, 'filterError' => $error];
    }

    /**
     * Malaysian public holidays for the riders' work states, keyed by date. State holidays name the states they apply to.
     *
     * @return Collection<string, Collection<int, string>>
     */
    private function holidays(CarbonImmutable $from, CarbonImmutable $until, ?AttendanceAccess $access = null): Collection
    {
        $states = ($access ?? app(AttendanceAccess::class))->riders(auth()->user())->whereNotNull('work_state_code')->distinct()->pluck('work_state_code');
        $labels = ReferenceValue::query()->where('category', 'state')->pluck('label', 'code');

        return PublicHoliday::query()->where('is_active', true)->whereBetween('holiday_date', [$from->toDateString(), $until->toDateString()])
            ->when($states->isNotEmpty(), fn ($q) => $q->where(fn ($q) => $q->whereNull('state_code')->orWhereIn('state_code', $states)))->orderBy('holiday_date')->get()
            ->groupBy(fn (PublicHoliday $holiday) => $holiday->holiday_date->toDateString())
            ->map(fn (Collection $list) => $list->groupBy('name')->map(function (Collection $same, string $name) use ($labels): string {
                if ($same->contains(fn ($holiday) => $holiday->state_code === null)) {
                    return $name;
                }
                $names = $same->pluck('state_code')->map(fn ($code) => $labels[$code] ?? $code);

                return $name.' ('.($names->count() > 2 ? $names->count().' states' : $names->join(', ')).')';
            })->values());
    }

    private function today(): CarbonImmutable
    {
        return now()->timezone(config('dcms.business_timezone'))->toImmutable()->startOfDay();
    }

    private function selectedMonth(): CarbonImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)) {
            return CarbonImmutable::createFromFormat('!Y-m', $this->month, config('dcms.business_timezone'));
        }

        return $this->today()->startOfMonth();
    }

    private function validDate(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            return null;
        }

        return CarbonImmutable::parse($value, config('dcms.business_timezone'))->startOfDay();
    }
}
