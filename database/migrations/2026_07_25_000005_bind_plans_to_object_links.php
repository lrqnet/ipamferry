<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_plans', function (Blueprint $table): void {
            $table->jsonb('identity_links')->default('[]');
        });

        Schema::table('migration_object_links', function (Blueprint $table): void {
            $table->dropUnique('migration_links_source_unique');
            $table->unique(
                [
                    'project_id',
                    'source_instance_fingerprint',
                    'source_type',
                    'source_id',
                    'target_instance_fingerprint',
                ],
                'migration_links_source_target_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('migration_object_links', function (Blueprint $table): void {
            $table->dropUnique('migration_links_source_target_unique');
            $table->unique(
                ['project_id', 'source_instance_fingerprint', 'source_type', 'source_id'],
                'migration_links_source_unique',
            );
        });

        Schema::table('migration_plans', function (Blueprint $table): void {
            $table->dropColumn('identity_links');
        });
    }
};
