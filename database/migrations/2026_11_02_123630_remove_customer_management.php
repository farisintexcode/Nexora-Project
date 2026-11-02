<?php

use Database\Seeders\ImportRegistrySeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Removes the Customer Management module: categories, the customer import kind and permissions only its pages used.
 * Debtors stay as the internal record behind tasks, calls and communications, and the task import contract gains the columns that create them.
 */
return new class extends Migration
{
    private const RETIRED_PERMISSIONS = ['customers.create', 'customers.update', 'customers.categorize', 'customers.manage_categories', 'customers.view_sensitive'];

    public function up(): void
    {
        $this->assertNoCustomerImports();

        DB::unprepared(<<<'SQL'
            DROP TABLE customer_category_assignments;
            DROP TABLE customer_categories;
            DROP FUNCTION dcms_category_membership_guard();
        SQL);

        DB::statement('ALTER TABLE import_templates DISABLE TRIGGER import_template_immutable');
        DB::statement('ALTER TABLE import_fields DISABLE TRIGGER import_field_immutable');
        DB::table('import_templates')->where('kind', 'customer')->delete();
        DB::table('import_fields')->where('module', 'customer')->delete();
        Model::unguarded(fn () => (new ImportRegistrySeeder)->run());
        $this->publishTaskContract();
        DB::statement('ALTER TABLE import_fields ENABLE TRIGGER import_field_immutable');
        DB::statement('ALTER TABLE import_templates ENABLE TRIGGER import_template_immutable');

        $this->rewriteCheck('import_fields', "'customer'::character varying, ", '');
        $this->rewriteCheck('import_templates', "'customer'::character varying, ", '');
        $this->rewriteCheck('import_rows', 'target_customer_id, ', '');
        $this->removeCustomerRowGuard();
        Schema::table('import_rows', fn ($table) => $table->dropColumn('target_customer_id'));

        DB::table('permissions')->whereIn('name', self::RETIRED_PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        throw new RuntimeException('Removing Customer Management cannot be rolled back. Restore a database backup taken before this migration.');
    }

    /** Stops before any change, because customer imports keep immutable history that this migration would have to destroy. */
    private function assertNoCustomerImports(): void
    {
        $templates = DB::table('import_templates')->where('kind', 'customer')->pluck('id');
        if (DB::table('import_batches')->where('kind', 'customer')->exists() || DB::table('import_mapping_profiles')->whereIn('template_id', $templates)->exists()) {
            throw new RuntimeException('Customer import history exists. Archive and remove it before running this migration.');
        }
    }

    private function publishTaskContract(): void
    {
        $fields = DB::table('import_fields')->where('module', 'task')->whereIn('canonical_field', ImportRegistrySeeder::TASK_FIELDS)->orderBy('id')->pluck('id')->all();
        foreach (DB::table('import_templates')->where('kind', 'task')->get(['id', 'schema_definition']) as $template) {
            $definition = json_decode($template->schema_definition, true, flags: JSON_THROW_ON_ERROR);
            DB::table('import_templates')->where('id', $template->id)->update(['schema_definition' => json_encode(['field_ids' => $fields] + $definition, JSON_THROW_ON_ERROR)]);
        }
    }

    private function rewriteCheck(string $table, string $search, string $replacement): void
    {
        $definition = DB::selectOne('SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?', ["{$table}_values"])->definition;
        DB::statement("ALTER TABLE $table DROP CONSTRAINT {$table}_values");
        DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_values ".str_replace($search, $replacement, $definition));
    }

    private function removeCustomerRowGuard(): void
    {
        $definition = DB::selectOne("SELECT pg_get_functiondef('import_row_guard'::regproc) AS definition")->definition;
        DB::unprepared(preg_replace('/^.*target_customer_id.*\R/m', '', $definition));
    }
};
