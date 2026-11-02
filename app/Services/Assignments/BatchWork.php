<?php

namespace App\Services\Assignments;

use App\Models\AssignmentBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** M03 archive disposition. Future intake/task actions must lock the parent batch first. */
class BatchWork
{
    /** @return array{tasks: ?int, unfinished_tasks: int, imports: ?int, unfinished_imports: int} */
    public function summary(AssignmentBatch $batch): array
    {
        $result = ['tasks' => null, 'unfinished_tasks' => 0, 'imports' => null, 'unfinished_imports' => 0];
        if (Schema::hasTable('tasks')) {
            $tasks = DB::table('tasks')->where('assignment_batch_id', $batch->id)->whereNull('deleted_at');
            $result['tasks'] = (clone $tasks)->count();
            $result['unfinished_tasks'] = $tasks->where(fn ($q) => $q->whereNotIn('work_status', ['completed', 'cancelled'])->orWhere(fn ($q) => $q->where('work_status', 'completed')->where('review_status', '!=', 'approved')))->count();
        }
        if (Schema::hasTable('import_batches')) {
            $imports = DB::table('import_batches')->where('assignment_batch_id', $batch->id);
            $result['imports'] = (clone $imports)->count();
            $result['unfinished_imports'] = $imports->where(fn ($q) => $q->whereNotIn('status', ['completed', 'failed', 'cancelled'])->orWhereRaw('accepted_count <> processed_count + failed_count'))->count();
        }

        if (Schema::hasTable('bulk_operations')) {
            $result['unfinished_tasks'] += DB::table('bulk_operations')->where('assignment_batch_id', $batch->id)->whereIn('status', ['queued', 'running'])->count();
        }

        return $result;
    }
}
