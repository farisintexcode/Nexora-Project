<?php

namespace Database\Factories;

use App\Models\PublicHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicHoliday>
 */
class PublicHolidayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['holiday_date' => fake()->dateTimeBetween('now', '+1 year')->format('Y-m-d'), 'name' => fake()->unique()->words(3, true), 'state_code' => null, 'source' => 'manual', 'is_active' => true];
    }

    /** A holiday observed in one state only. */
    public function forState(string $stateCode): static
    {
        return $this->state(['state_code' => $stateCode]);
    }
}
