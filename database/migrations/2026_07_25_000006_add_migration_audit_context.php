<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_events', function (Blueprint $table): void {
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('migration_plans')->nullOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained('migration_executions')->nullOnDelete();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('migration_events', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'created_at']);
            $table->dropConstrainedForeignId('execution_id');
            $table->dropConstrainedForeignId('plan_id');
            $table->dropConstrainedForeignId('actor_id');
        });
    }
};
