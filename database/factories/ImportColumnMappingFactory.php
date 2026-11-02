<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImportColumnMappingFactory extends Factory
{
    public function definition(): array
    {
        return ['import_batch_id' => ImportBatch::factory(), 'source_sheet' => 'Intake', 'source_column_index' => 1, 'original_header' => 'External Reference', 'normalized_header' => 'external reference', 'match_method' => 'unmatched', 'is_ignored' => false];
    }
}
