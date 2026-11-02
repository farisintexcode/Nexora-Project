<?php

use App\Models\User;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

function staffManager(): User
{
    $user = User::factory()->create();
    $user->assignRole('Admin');

    return $user;
}

function staffInput(array $overrides = []): array
{
    return array_replace(['staff_no' => '000123', 'first_name' => 'Nadia', 'last_name' => 'Test', 'email' => 'nadia@example.test', 'gender_code' => 'female', 'position_code' => '01', 'appointment_date' => '2026-01-02', 'phone' => '+60123456789', 'home_phone' => '', 'address' => '10 Synthetic Test Road', 'is_active' => true, 'is_rider' => true, 'reason' => ''], $overrides);
}

require_once __DIR__.'/CommunicationFixtures.php';
