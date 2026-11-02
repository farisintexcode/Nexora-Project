<?php

use Database\Seeders\ImportRegistrySeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M08: what a customer owes, and what a Rider says they paid.
 * Each balance is an append-only encrypted snapshot, so a new upload, edit or verified payment adds a row and never overwrites one.
 * A snapshot is not a receipt: only an approved payment claim counts as collected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->timestampsTz(6);
            $table->foreignId('task_id')->constrained('tasks')->restrictOnDelete();
            $table->foreignId('debt_account_id')->constrained('debt_accounts')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submission_id')->nullable()->constrained('task_submissions')->restrictOnDelete();
            $table->string('source_code', 16);
            $table->char('currency', 3)->default('MYR');
            $table->text('outstanding_balance_ciphertext')->nullable();
            $table->text('monthly_charge_ciphertext')->nullable();
            $table->text('paid_amount_ciphertext')->nullable();
            $table->date('balance_date')->nullable();
            $table->index(['task_id', 'id']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE financial_snapshots ADD CONSTRAINT financial_snapshots_values CHECK (
                source_code IN ('import', 'manual', 'payment')
                AND (source_code <> 'payment' OR (submission_id IS NOT NULL AND paid_amount_ciphertext IS NOT NULL))
            );
            CREATE TRIGGER financial_snapshot_immutable BEFORE UPDATE OR DELETE ON financial_snapshots FOR EACH ROW EXECUTE FUNCTION dcms_task_append_guard();
        SQL);

        Schema::table('collection_activities', function (Blueprint $table): void {
            $table->text('paid_amount_ciphertext')->nullable();
        });

        $this->publishTaskContract();
    }

    public function down(): void
    {
        if (DB::table('financial_snapshots')->exists()) {
            throw new RuntimeException('Financial snapshots exist. Restore a database backup taken before this migration to remove them.');
        }
        Schema::drop('financial_snapshots');
        Schema::table('collection_activities', fn (Blueprint $table) => $table->dropColumn('paid_amount_ciphertext'));
    }

    private function publishTaskContract(): void
    {
        DB::statement('ALTER TABLE import_templates DISABLE TRIGGER import_template_immutable');
        DB::statement('ALTER TABLE import_fields DISABLE TRIGGER import_field_immutable');
        Model::unguarded(fn () => (new ImportRegistrySeeder)->run());
        $fields = DB::table('import_fields')->where('module', 'task')->whereIn('canonical_field', ImportRegistrySeeder::TASK_FIELDS)->orderBy('id')->pluck('id')->all();
        foreach (DB::table('import_templates')->where('kind', 'task')->get(['id', 'schema_definition']) as $template) {
            $definition = json_decode($template->schema_definition, true, flags: JSON_THROW_ON_ERROR);
            DB::table('import_templates')->where('id', $template->id)->update(['schema_definition' => json_encode(['field_ids' => $fields, 'required' => ImportRegistrySeeder::TASK_REQUIRED] + $definition, JSON_THROW_ON_ERROR)]);
        }
        DB::statement('ALTER TABLE import_fields ENABLE TRIGGER import_field_immutable');
        DB::statement('ALTER TABLE import_templates ENABLE TRIGGER import_template_immutable');
    }
};
