<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Deactivated staff now have their login disabled; bring accounts of staff deactivated before this change in line. */
return new class extends Migration
{
    public function up(): void
    {
        $accounts = DB::table('users')
            ->whereIn('status', ['active', 'invited'])
            ->whereIn('staff_id', DB::table('staff')->where('is_active', false)->select('id'));

        DB::table('password_reset_tokens')->whereIn('email', (clone $accounts)->select('email'))->delete();
        $accounts->update(['status' => 'disabled', 'remember_token' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Disabled logins stay disabled; reactivating the staff record restores each one.
    }
};
