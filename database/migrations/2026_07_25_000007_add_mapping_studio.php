<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_projects', function (Blueprint $table): void {
            $table->unsignedInteger('mapping_revision')->default(1);
            $table->jsonb('mapping_catalog')->nullable();
        });

        Schema::create('mapping_previews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained('migration_projects')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->index();
            $table->unsignedInteger('mapping_revision');
            $table->string('source_fingerprint', 64);
            $table->string('target_fingerprint', 64);
            $table->string('mapping_fingerprint', 64);
            $table->jsonb('result')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mapping_previews');

        Schema::table('migration_projects', function (Blueprint $table): void {
            $table->dropColumn(['mapping_revision', 'mapping_catalog']);
        });
    }
};
