<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table): void {
            $table->string('position_code', 32)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('staff')->whereNull('position_code')->exists()) {
            throw new RuntimeException('Legacy position cannot become required while staff without a position exist. Preserve their records and use a forward migration.');
        }
        Schema::table('staff', function (Blueprint $table): void {
            $table->string('position_code', 32)->nullable(false)->change();
        });
    }
};
