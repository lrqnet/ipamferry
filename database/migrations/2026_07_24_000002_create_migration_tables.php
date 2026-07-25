<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('source_kind', 32);
            $table->string('status', 32)->default('draft')->index();
            $table->foreignId('created_by')->constrained('users');
            $table->jsonb('mapping')->nullable();
            $table->jsonb('source_snapshot')->nullable();
            $table->jsonb('target_snapshot')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
        Schema::create('migration_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('migration_projects')->cascadeOnDelete();
            $table->string('fingerprint', 64)->index();
            $table->jsonb('actions');
            $table->jsonb('conflicts')->default('[]');
            $table->jsonb('warnings')->default('[]');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('migration_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('migration_projects')->cascadeOnDelete();
            $table->string('kind', 64);
            $table->string('level', 16)->default('info');
            $table->jsonb('context')->default('{}');
            $table->timestamps();
        });
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('migration_events');
        Schema::dropIfExists('migration_plans');
        Schema::dropIfExists('migration_projects');
    }
};
