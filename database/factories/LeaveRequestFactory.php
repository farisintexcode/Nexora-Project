<?php

namespace Database\Factories;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LeaveRequest> */
class LeaveRequestFactory extends Factory
{
    public function definition(): array
    {
        return ['request_key' => (string) Str::uuid7(), 'rider_id' => Staff::factory()->state(['is_rider' => true]), 'requested_by' => fn (array $attributes) => User::factory()->create(['staff_id' => $attributes['rider_id']])->id, 'leave_type_id' => LeaveType::factory(), 'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDay()->toDateString(), 'total_days' => 1, 'reason' => fake()->sentence(), 'type_snapshot' => fn (array $attributes) => ['name' => LeaveType::findOrFail($attributes['leave_type_id'])->name, 'allow_pending_cancel' => false], 'calendar_snapshot' => [], 'status' => 'pending', 'submitted_at' => now()];
    }
}
