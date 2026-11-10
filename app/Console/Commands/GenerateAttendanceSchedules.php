<?php

namespace App\Console\Commands;

use App\Models\Staff;
use App\Services\Attendance\WorkCalendar;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('attendance:generate-schedules')]
#[Description('Put today (and any recently missed days) on every active Rider\'s attendance calendar')]
class GenerateAttendanceSchedules extends Command
{
    public function handle(WorkCalendar $calendar): int
    {
        $today = now()->timezone(config('dcms.business_timezone'));
        $from = $today->copy()->subDays((int) config('attendance.backfill_days', 7))->toDateString();
        $generated = 0;
        $skipped = 0;

        Staff::query()->where('is_rider', true)->where('is_active', true)->orderBy('id')->select('id', 'staff_no')
            ->each(function (Staff $rider) use ($calendar, $from, $today, &$generated, &$skipped): void {
                try {
                    $calendar->ensure($rider->id, $from, $today->toDateString());
                    $generated++;
                } catch (ValidationException $exception) {
                    $skipped++;
                    $this->warn("{$rider->staff_no}: ".collect($exception->errors())->flatten()->first());
                }
            });

        $this->info("Calendars checked for {$generated} Riders; {$skipped} skipped.");

        return self::SUCCESS;
    }
}
