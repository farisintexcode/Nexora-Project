<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Default policies apply to every Rider from their start date; public holidays are national or per state.
     */
    public function up(): void
    {
        foreach (['putrajaya' => 'Putrajaya', 'labuan' => 'Labuan'] as $code => $label) {
            DB::table('reference_values')->insertOrIgnore(['public_id' => (string) Str::uuid7(), 'category' => 'state', 'code' => $code, 'label' => $label]);
        }

        Schema::table('attendance_policies', function (Blueprint $t): void {
            $t->boolean('is_default')->default(false);
            $t->date('effective_from')->nullable();
            $t->index(['is_default', 'effective_from']);
        });
        DB::statement('ALTER TABLE attendance_policies ADD CONSTRAINT attendance_policy_default_start CHECK (NOT is_default OR effective_from IS NOT NULL)');

        Schema::table('staff', function (Blueprint $t): void {
            $t->string('work_state_category', 50)->default('state');
            $t->string('work_state_code', 64)->nullable()->index();
            $t->foreign(['work_state_category', 'work_state_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE staff ADD CONSTRAINT staff_work_state_values CHECK (work_state_category = 'state')");

        Schema::create('public_holidays', function (Blueprint $t): void {
            $t->id();
            $t->timestampsTz();
            $t->date('holiday_date');
            $t->string('name', 150);
            $t->string('state_category', 50)->default('state');
            $t->string('state_code', 64)->nullable();
            $t->string('source', 24);
            $t->string('external_id', 120)->nullable();
            $t->boolean('is_tentative')->default(false);
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->index(['holiday_date', 'state_code']);
            $t->foreign(['state_category', 'state_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE public_holidays ADD CONSTRAINT public_holiday_values CHECK (state_category = 'state' AND source IN ('online','manual') AND length(trim(name)) > 0)");
        DB::statement('CREATE UNIQUE INDEX public_holiday_national_unique ON public_holidays (holiday_date, name) WHERE state_code IS NULL');
        DB::statement('CREATE UNIQUE INDEX public_holiday_state_unique ON public_holidays (holiday_date, name, state_code) WHERE state_code IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX public_holiday_external_unique ON public_holidays (external_id, COALESCE(state_code, '')) WHERE external_id IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('public_holidays');
        DB::statement('ALTER TABLE staff DROP CONSTRAINT IF EXISTS staff_work_state_values');
        Schema::table('staff', function (Blueprint $t): void {
            $t->dropForeign(['work_state_category', 'work_state_code']);
            $t->dropColumn(['work_state_category', 'work_state_code']);
        });
        DB::statement('ALTER TABLE attendance_policies DROP CONSTRAINT IF EXISTS attendance_policy_default_start');
        Schema::table('attendance_policies', function (Blueprint $t): void {
            $t->dropIndex(['is_default', 'effective_from']);
            $t->dropColumn(['is_default', 'effective_from']);
        });
    }
};
