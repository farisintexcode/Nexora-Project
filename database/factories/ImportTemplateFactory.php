<?php

namespace Database\Factories;

use App\Models\ImportField;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImportTemplateFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('synthetic-######'), 'version' => 1, 'kind' => 'assignment_batch', 'schema_definition' => ['field_ids' => ImportField::query()->where('module', 'assignment_batch')->pluck('id')->all(), 'required' => ['customer_reference', 'party_type', 'name', 'status'], 'identity' => 'customer_reference'], 'is_active' => true, 'created_by' => User::factory()];
    }
}
