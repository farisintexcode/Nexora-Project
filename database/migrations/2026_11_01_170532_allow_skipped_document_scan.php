<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Records workbooks that were not scanned because scanning is not required, rather than labelling them clean. */
return new class extends Migration
{
    private const CHECK = "size_bytes >= 0 AND document_type_category = 'document_type' AND image_category_category = 'image_category' AND owner_type IN ('task','activity','import','export','user','communication')";

    public function up(): void
    {
        $this->replaceCheck("'pending','clean','rejected','error','skipped'");
    }

    public function down(): void
    {
        DB::table('documents')->where('scan_status', 'skipped')->update(['scan_status' => 'pending']);
        $this->replaceCheck("'pending','clean','rejected','error'");
    }

    private function replaceCheck(string $statuses): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_values');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_values CHECK ('.self::CHECK." AND scan_status IN ($statuses))");
    }
};
