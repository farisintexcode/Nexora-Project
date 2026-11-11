<?php

namespace App\Livewire\Admin\Attendance;

use App\Models\Attendance;
use App\Models\PublicHoliday;
use App\Models\ReferenceValue;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\AttendanceRegister;
use Carbon\CarbonImmutable;
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

    public const VIEWS = ['today', 'calendar', 'records'];

    /** Which riders count in each group of the day board, in display order. */
    public const DAY_GROUPS = [
        'working' => 'Clocked in',
        'done' => 'Clocked out',
        'leave' => 'On leave',
        'waiting' => 'Not clocked in',
        'off' => 'Rest day / holiday',
    ];

    #[Url]
    public string $view = 'today';

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

    public function mount(): void
    {
        if (! request()->has('view') && request()->hasAny(['from', 'until', 'status'])) {
            $this->view = 'records';
        }
        $this->search = (string) session('attendance.filters.'.auth()->id().'.search', '');
        $this->from = $this->from ?: $this->today()->startOfMonth()->toDateString();
        $this->until = $this->until ?: $this->today()->toDateString();
    }

    public function updated(): void
    {
        $this->resetPage();
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

    /** Open the day board for a calendar date. */
    public function showDay(string $date): void
    {
        $this->day = $this->validDate($date)?->toDateString() ?? '';
        $this->view = 'today';
    }

    public function shiftDay(int $days): void
    {
        $this->day = $this->selectedDay()->addDays(max(-1, min(1, $days)))->toDateString();
    }

    public function shiftMonth(int $months): void
    {
        $this->month = $this->selectedMonth()->addMonths(max(-1, min(1, $months)))->format('Y-m');
    }

    public function render(AttendanceRegister $register, AttendanceAccess $access): View
    {
        Gate::authorize('viewAny', Attendance::class);
        $view = in_array($this->view, self::VIEWS, true) ? $this->view : 'today';
        $data = ['view' => $view, 'riders' => $access->riders(auth()->user())->orderBy('staff_no')->limit(200)->get(), 'asOf' => now()->toImmutable()];

        return view('livewire.admin.attendance.index', [...$data, ...match ($view) {
            'today' => $this->dayBoard($register),
            'calendar' => $this->calendar($register, $access),
            default => $this->records($register, $data['asOf']),
        }]);
    }

    /** @return array{boardDay: CarbonImmutable, isToday: bool, groups: array<string, Collection>, holidays: Collection} */
    private function dayBoard(AttendanceRegister $register): array
    {
        $day = $this->selectedDay();
        $rows = $register->query(auth()->user(), ['from' => $day->toDateString(), 'until' => $day->toDateString(), 'rider' => $this->rider], asOf: now()->toImmutable())
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

        return ['boardDay' => $day, 'isToday' => $day->isSameDay($this->today()), 'groups' => $groups, 'holidays' => $this->holidays($day, $day)->get($day->toDateString(), collect())];
    }

    /** @return array{calendarMonth: CarbonImmutable, weeks: array<int, list<CarbonImmutable>>, days: Collection, holidays: Collection} */
    private function calendar(AttendanceRegister $register, AttendanceAccess $access): array
    {
        $month = $this->selectedMonth();
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();
        $days = $register->query(auth()->user(), ['from' => $start->toDateString(), 'until' => $end->toDateString(), 'rider' => $this->rider], asOf: now()->toImmutable())
            ->selectRaw("service_date::date AS day, count(*) FILTER (WHERE result_status = 'leave') AS on_leave, count(*) FILTER (WHERE raw_clock_in_at IS NOT NULL AND result_status <> 'leave') AS clocked_in, count(*) FILTER (WHERE result_status = 'absent') AS absent, count(*) FILTER (WHERE result_status IN ('incomplete', 'open')) AS unfinished, count(*) FILTER (WHERE pending_leave) AS leave_pending")
            ->groupByRaw('service_date::date')->get()->keyBy(fn ($row) => (string) $row->day);
        $gridDays = [];
        for ($cursor = $start->startOfWeek(CarbonImmutable::MONDAY); $cursor->lte($end->endOfWeek(CarbonImmutable::SUNDAY)); $cursor = $cursor->addDay()) {
            $gridDays[] = $cursor;
        }

        return ['calendarMonth' => $month, 'weeks' => array_chunk($gridDays, 7), 'days' => $days, 'holidays' => $this->holidays($start, $end, $access)];
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

    private function selectedDay(): CarbonImmutable
    {
        return $this->validDate($this->day) ?? $this->today();
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
