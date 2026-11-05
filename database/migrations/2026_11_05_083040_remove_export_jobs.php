<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Reports now download immediately, so the stored export pipeline is removed. Audit history is retained. */
return new class extends Migration
{
    private const OWNER_RULE = "IF NEW.owner_type='export' AND NOT EXISTS(SELECT 1 FROM export_jobs WHERE id=NEW.owner_id AND status='running' AND requested_by=NEW.uploaded_by AND NEW.document_type_code='report_'||kind AND NEW.scan_status='clean' AND NEW.disk='s3' AND NEW.activity_id IS NULL AND ((kind='csv' AND NEW.mime_type='text/csv') OR (kind='pdf' AND NEW.mime_type='application/pdf'))) THEN RAISE EXCEPTION 'Report document context unavailable' USING ERRCODE='23514'; END IF; IF NEW.owner_type NOT IN ('import','user','task','activity','communication','export')";

    public function up(): void
    {
        $artifacts = DB::table('documents')->where('owner_type', 'export')->get(['disk', 'object_key']);

        Schema::dropIfExists('export_jobs');
        DB::statement('DROP FUNCTION IF EXISTS export_job_guard()');
        DB::statement('DROP INDEX IF EXISTS report_document_unique');
        DB::table('documents')->where('owner_type', 'export')->delete();
        DB::table('outbox_events')->where('event_type', 'report.generate')->delete();
        DB::table('processed_events')->where('consumer', 'report-export')->delete();
        DB::table('reference_values')->where('category', 'document_type')->whereIn('code', ['report_csv', 'report_pdf'])->delete();

        $definition = DB::selectOne("SELECT pg_get_functiondef('import_document_guard()'::regprocedure) AS definition")->definition;
        if (! str_contains($definition, self::OWNER_RULE)) {
            throw new RuntimeException('The document owner rule changed. Review this migration before running it.');
        }
        DB::unprepared(str_replace(self::OWNER_RULE, "IF NEW.owner_type NOT IN ('import','user','task','activity','communication')", $definition));
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_values');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_values CHECK (size_bytes >= 0 AND document_type_category = 'document_type' AND image_category_category = 'image_category' AND owner_type IN ('task','activity','import','user','communication') AND scan_status IN ('pending','clean','rejected','error','skipped'))");

        foreach ($artifacts as $artifact) {
            try {
                Storage::disk($artifact->disk)->delete($artifact->object_key);
            } catch (Throwable) {
                Log::warning('report_artifact_cleanup_pending');
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Removed report exports cannot be restored. Recover from a database backup if they are needed.');
    }
};
