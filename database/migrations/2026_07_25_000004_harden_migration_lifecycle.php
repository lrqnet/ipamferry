<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_projects', function (Blueprint $table): void {
            $table->unsignedSmallInteger('snapshot_schema_version')->default(1);
            $table->jsonb('source_instance')->nullable();
            $table->jsonb('target_instance')->nullable();
            $table->jsonb('discovery_manifest')->nullable();
        });

        Schema::table('migration_plans', function (Blueprint $table): void {
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('engine_version', 32)->default('dev');
            $table->string('locale', 16)->default('en');
            $table->string('source_fingerprint', 64)->nullable();
            $table->string('target_fingerprint', 64)->nullable();
            $table->string('mapping_fingerprint', 64)->nullable();
            $table->string('target_instance_fingerprint', 64)->nullable();
            $table->jsonb('target_instance')->nullable();
            $table->jsonb('mapping_snapshot')->default('{}');
            $table->jsonb('preservation')->default('{}');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['project_id', 'fingerprint']);
        });

        Schema::create('migration_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('migration_projects')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('migration_plans')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->index();
            $table->string('target_instance_fingerprint', 64);
            $table->jsonb('summary')->default('{}');
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['plan_id', 'status']);
        });

        Schema::create('migration_action_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_id')->constrained('migration_executions')->cascadeOnDelete();
            $table->unsignedInteger('action_index');
            $table->string('action_key', 64);
            $table->string('operation', 16);
            $table->string('status', 24)->index();
            $table->string('target_type', 64);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('request_id', 128)->nullable();
            $table->string('payload_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->jsonb('result')->default('{}');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['execution_id', 'action_key']);
        });

        Schema::create('migration_object_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('migration_projects')->cascadeOnDelete();
            $table->string('source_instance_fingerprint', 64);
            $table->string('source_type', 64);
            $table->string('source_id', 191);
            $table->string('target_instance_fingerprint', 64);
            $table->string('target_type', 64);
            $table->unsignedBigInteger('target_id');
            $table->string('natural_key', 512);
            $table->jsonb('target_snapshot')->default('{}');
            $table->timestamps();
            $table->unique(
                ['project_id', 'source_instance_fingerprint', 'source_type', 'source_id'],
                'migration_links_source_unique'
            );
            $table->index(
                ['target_instance_fingerprint', 'target_type', 'target_id'],
                'migration_links_target_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_object_links');
        Schema::dropIfExists('migration_action_results');
        Schema::dropIfExists('migration_executions');

        Schema::table('migration_plans', function (Blueprint $table): void {
            $table->dropUnique(['project_id', 'fingerprint']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'schema_version',
                'engine_version',
                'locale',
                'source_fingerprint',
                'target_fingerprint',
                'mapping_fingerprint',
                'target_instance_fingerprint',
                'target_instance',
                'mapping_snapshot',
                'preservation',
            ]);
        });

        Schema::table('migration_projects', function (Blueprint $table): void {
            $table->dropColumn([
                'snapshot_schema_version',
                'source_instance',
                'target_instance',
                'discovery_manifest',
            ]);
        });
    }
};
