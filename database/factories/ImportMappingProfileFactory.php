<?php

namespace Database\Factories;

use App\Models\ImportTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImportMappingProfileFactory extends Factory
{
    public function definition(): array
    {
        return ['template_id' => fn () => ImportTemplate::query()->where('kind', 'assignment_batch')->value('id'), 'code' => fake()->unique()->bothify('synthetic-######'), 'version' => 1, 'name' => 'Synthetic profile', 'mapping_definition' => [], 'created_by' => User::factory()];
    }
}
