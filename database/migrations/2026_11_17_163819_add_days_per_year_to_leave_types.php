<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each leave type now has one setting, "Maximum leave days per year", that applies to every year. It replaces the
 * per-request calendar span, the optional tracking switch, the applications unit and the per-year entitlements.
 * Every type is counted in working days; the old per-request span becomes a fixed 93-day safety net.
 * Old entitlement rows are kept for history but no longer read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->unsignedSmallInteger('days_per_year')->nullable()->after('max_calendar_days');
        });
        DB::statement('ALTER TABLE leave_types ADD CONSTRAINT leave_type_days_per_year CHECK (days_per_year IS NULL OR days_per_year <= 366)');

        $year = now()->timezone(config('dcms.business_timezone'))->year;
        foreach (DB::table('leave_types')->get(['id', 'tracks_balance', 'limit_unit']) as $type) {
            $days = $type->tracks_balance && $type->limit_unit === 'days'
                ? DB::table('leave_entitlements')->where('leave_type_id', $type->id)->where('leave_year', '<=', $year)->orderByDesc('leave_year')->value('days')
                : null;
            DB::table('leave_types')->where('id', $type->id)->update([
                'days_per_year' => $days,
                'tracks_balance' => true,
                'limit_unit' => 'days',
                'max_calendar_days' => 93,
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_at' => now(),
            ]);
        }
    }

    /** Removes the yearly setting; the previous spans and tracking switches are not restored. */
    public function down(): void
    {
        DB::statement('ALTER TABLE leave_types DROP CONSTRAINT IF EXISTS leave_type_days_per_year');
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->dropColumn('days_per_year');
        });
    }
};
