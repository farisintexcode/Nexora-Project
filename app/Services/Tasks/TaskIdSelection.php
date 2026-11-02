<?php

namespace App\Services\Tasks;

use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/** M05 / Assign Task: reads typed task IDs such as 12, 12, 15, 10-20 or 10-20, 25 within one assignment. */
class TaskIdSelection
{
    public const MODES = ['single' => 'Single', 'selected' => 'Selected', 'range' => 'Range', 'range_selected' => 'Range and Selected'];

    private const MAX_TASKS = 200;

    private const FILTER_ERROR = 'Enter task IDs such as 12, or 12, 15, 18, or 10-20, or 10-20, 25.';

    private const MODE_ERRORS = [
        'single' => 'Enter one task ID, for example 12.',
        'selected' => 'Enter task IDs separated by commas, for example 12, 15, 18.',
        'range' => 'Enter one range, for example 10-20.',
        'range_selected' => 'Enter at least one range and one task ID, for example 10-20, 25.',
    ];

    /**
     * @return array<int, int> ids of the matching tasks in the assignment
     */
    public function resolve(int $assignmentId, string $mode, string $input): array
    {
        [$exact, $ranges] = $this->parse($mode, $input);
        $tasks = Task::query()->where('assignment_batch_id', $assignmentId)->whereNotNull('source_task_number')
            ->where(function ($query) use ($exact, $ranges): void {
                $query->whereIn('source_task_number', $exact);
                foreach ($ranges as [$from, $to]) {
                    $query->orWhereBetween('source_task_number', [$from, $to]);
                }
            })->orderBy('source_task_number')->limit(self::MAX_TASKS + 1)->get(['id', 'source_task_number']);
        $missing = array_diff($exact, $tasks->pluck('source_task_number')->all());
        if ($missing !== []) {
            throw ValidationException::withMessages(['taskIds' => 'Not found in this assignment: '.implode(', ', $missing).'.']);
        }
        if ($tasks->isEmpty()) {
            throw ValidationException::withMessages(['taskIds' => 'No tasks in this assignment match that range.']);
        }
        if ($tasks->count() > self::MAX_TASKS) {
            throw ValidationException::withMessages(['taskIds' => 'Choose up to '.self::MAX_TASKS.' tasks at a time.']);
        }

        return $tasks->pluck('id')->all();
    }

    /**
     * Narrows a report to typed IDs in any mix, with no mode to choose: 12, or 12, 15, or 10-20, or 10-20, 25.
     */
    public function filter(Builder $query, string $input): void
    {
        [$exact, $ranges] = $this->parse(null, $input);
        $query->whereNotNull('source_task_number')->where(function (Builder $query) use ($exact, $ranges): void {
            $query->whereIn('source_task_number', $exact);
            foreach ($ranges as [$from, $to]) {
                $query->orWhereBetween('source_task_number', [$from, $to]);
            }
        });
    }

    public function filterError(string $input): ?string
    {
        try {
            $this->parse(null, $input);
        } catch (ValidationException $error) {
            return $error->validator->errors()->first();
        }

        return null;
    }

    /**
     * @return array{0: array<int, int>, 1: array<int, array{0: int, 1: int}>} exact numbers and from-to ranges
     */
    private function parse(?string $mode, string $input): array
    {
        $exact = [];
        $ranges = [];
        foreach (array_filter(array_map('trim', explode(',', $input)), fn (string $part): bool => $part !== '') as $part) {
            if (! preg_match('/^([A-Za-z]*\d+)(?:\s*-\s*([A-Za-z]*\d+))?$/', $part, $match)) {
                throw ValidationException::withMessages(['taskIds' => self::MODE_ERRORS[$mode] ?? self::FILTER_ERROR]);
            }
            $from = Task::numberFromId($match[1]);
            $to = isset($match[2]) ? Task::numberFromId($match[2]) : $from;
            if ($from === null || $to === null) {
                throw ValidationException::withMessages(['taskIds' => self::MODE_ERRORS[$mode] ?? self::FILTER_ERROR]);
            }
            if ($from > $to) {
                throw ValidationException::withMessages(['taskIds' => "The range {$part} must start with the lower number."]);
            }
            if (isset($match[2])) {
                $ranges[] = [$from, $to];
            } else {
                $exact[] = $from;
            }
        }
        $exact = array_values(array_unique($exact));
        $valid = match ($mode) {
            null => $exact !== [] || $ranges !== [],
            'single' => count($exact) === 1 && $ranges === [],
            'selected' => $exact !== [] && $ranges === [],
            'range' => $exact === [] && count($ranges) === 1,
            'range_selected' => $exact !== [] && $ranges !== [],
            default => false,
        };
        if (! $valid) {
            throw ValidationException::withMessages(['taskIds' => ($mode === null ? self::FILTER_ERROR : (self::MODE_ERRORS[$mode] ?? 'Choose a selection mode.'))]);
        }

        return [$exact, $ranges];
    }
}
