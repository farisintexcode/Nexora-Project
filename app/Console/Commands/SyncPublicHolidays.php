<?php

namespace App\Console\Commands;

use App\Services\Attendance\PublicHolidays;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('attendance:sync-holidays {year? : Year to download; defaults to this year and next year}')]
#[Description('Download Malaysian national and state public holidays for attendance')]
class SyncPublicHolidays extends Command
{
    public function handle(PublicHolidays $holidays): int
    {
        if (! $holidays->syncConfigured()) {
            $this->warn('Skipped: MALAYSIA_CALENDAR_API_URL is not set.');

            return self::SUCCESS;
        }
        $thisYear = (int) now()->timezone(config('dcms.business_timezone'))->format('Y');
        $years = $this->argument('year') ? [(int) $this->argument('year')] : [$thisYear, $thisYear + 1];

        foreach ($years as $year) {
            try {
                $result = $holidays->sync(null, $year);
                $this->info("{$year}: {$result['total']} holidays ({$result['added']} new, {$result['updated']} changed, {$result['removed']} removed).");
            } catch (ValidationException $exception) {
                $this->error("{$year}: ".collect($exception->errors())->flatten()->first());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
