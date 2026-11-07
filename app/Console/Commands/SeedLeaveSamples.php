<?php

namespace App\Console\Commands;

use App\Actions\Attendance\ManageLeave;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Attendance\LeaveAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** M10 / leave_types: opt-in local samples, never statutory entitlements or production grants. */
class SeedLeaveSamples extends Command
{
    protected $signature = 'dcms:seed-leave-samples {actor : Email of an active authorized leave-policy administrator} {--activate : Make the clearly labelled local samples available for applications}';

    protected $description = 'Add missing labelled local leave-policy samples without modifying existing policies';

    public function handle(ManageLeave $workflow, LeaveAccess $access): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Sample leave policies are restricted to local development and isolated tests.');

            return self::FAILURE;
        }
        $actor = User::query()->where('email', mb_strtolower(trim($this->argument('actor'))))->first();
        if (! $actor || ! $access->configure($actor)) {
            $this->error('An active administrator with attendance.view_all and attendance.configure is required.');

            return self::FAILURE;
        }
        $count = DB::transaction(function () use ($actor, $workflow): int {
            $created = 0;
            foreach (['Annual Leave', 'Sick Leave', 'Emergency Leave', 'Unpaid Leave', 'Compassionate Leave'] as $label) {
                $name = 'Sample '.$label;
                if (LeaveType::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
                    continue;
                }
                $workflow->configure($actor, ['name' => $name, 'max_calendar_days' => 93, 'requires_document' => false, 'allow_past_dates' => false, 'allow_pending_cancel' => true, 'is_active' => (bool) $this->option('activate')]);
                $created++;
            }

            return $created;
        }, 3);
        $this->info($count.' labelled sample leave types added. Existing policies were not modified.');
        $this->warn('These are local examples, not statutory entitlements. The 93-day maximum is the existing technical request-span limit. Review organization policy before real use.');

        return self::SUCCESS;
    }
}
