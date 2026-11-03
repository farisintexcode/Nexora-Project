<?php

namespace Database\Factories;

use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveType> */
class LeaveTypeFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Synthetic '.fake()->unique()->word().' leave', 'configured_by' => User::factory(), 'is_active' => true, 'requires_document' => false, 'allow_pending_cancel' => false, 'max_calendar_days' => 30, 'lock_version' => 0];
    }
}
