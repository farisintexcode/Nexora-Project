<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes header aliases along with the Import configuration page, their only management screen.
 * Reviewed mappings keep their alias match_method and matched_rule_snapshot, so the match_method check is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_column_mappings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('alias_id');
        });
        Schema::dropIfExists('column_aliases');
        DB::statement('DROP FUNCTION IF EXISTS import_alias_guard()');
    }

    public function down(): void
    {
        Schema::create('column_aliases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->timestampsTz(6);
            $table->foreignId('field_id')->constrained('import_fields')->restrictOnDelete();
            $table->string('module', 40);
            $table->string('alias', 160);
            $table->string('normalized_alias', 160);
            $table->string('locale', 20)->nullable();
            $table->boolean('is_active')->default(true);
        });
        DB::statement('CREATE UNIQUE INDEX active_alias ON column_aliases (module, normalized_alias) WHERE is_active');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION import_alias_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.module <> (SELECT module FROM import_fields WHERE id=NEW.field_id) THEN RAISE EXCEPTION 'Alias module mismatch' USING ERRCODE='23514'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER import_alias_guard BEFORE INSERT OR UPDATE ON column_aliases FOR EACH ROW EXECUTE FUNCTION import_alias_guard();
        SQL);
        Schema::table('import_column_mappings', function (Blueprint $table): void {
            $table->foreignId('alias_id')->nullable()->constrained('column_aliases')->restrictOnDelete();
        });
    }
};
