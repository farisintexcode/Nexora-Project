<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets a proof photo with a skipped scan join a submission, for environments that do not require scanning.
 * Production always scans, so it never produces a skipped proof.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewriteGuard("d.scan_status='clean'", "d.scan_status IN ('clean','skipped')");
    }

    public function down(): void
    {
        $this->rewriteGuard("d.scan_status IN ('clean','skipped')", "d.scan_status='clean'");
    }

    private function rewriteGuard(string $search, string $replacement): void
    {
        $definition = DB::selectOne("SELECT pg_get_functiondef('submission_manifest_guard'::regproc) AS definition")->definition;
        if (! str_contains($definition, $search)) {
            throw new RuntimeException('The submission proof guard no longer matches the expected definition.');
        }
        DB::unprepared(str_replace($search, $replacement, $definition));
    }
};
