<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ImportFieldFactory extends Factory
{
    public function definition(): array
    {
        return ['module' => 'assignment_batch', 'canonical_field' => fake()->unique()->bothify('synthetic_######'), 'version' => 1, 'data_type' => 'string', 'validation_schema' => ['required' => false, 'transforms' => ['exact', 'trim']], 'is_active' => true];
    }
}
