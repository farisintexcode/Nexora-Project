<?php

namespace App\Services\Staff;

use App\Models\Staff;
use Carbon\CarbonInterface;

/**
 * Generates staff numbers in the format ST + YY + MM + NN (e.g. ST260403).
 *
 * The running number continues across months within a year and resets every new year.
 * Callers that persist the number must hold the staff advisory lock to stay race-safe.
 */
class StaffNumberGenerator
{
    public function next(?CarbonInterface $at = null): string
    {
        $at ??= now();
        $yearPrefix = 'ST'.$at->format('y');

        $highestSequence = (int) Staff::query()
            ->whereRaw('staff_no ~ ?', ['^'.$yearPrefix.'(0[1-9]|1[0-2])[0-9]{2,}$'])
            ->selectRaw('max(cast(substr(staff_no, 7) as integer)) as highest')
            ->value('highest');

        return $yearPrefix.$at->format('m').str_pad((string) ($highestSequence + 1), 2, '0', STR_PAD_LEFT);
    }
}
