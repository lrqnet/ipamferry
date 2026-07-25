<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_projects', function (Blueprint $table): void {
            $table->string('locale', 8)->default('en')->after('source_kind');
        });
    }

    public function down(): void
    {
        Schema::table('migration_projects', fn (Blueprint $table) => $table->dropColumn('locale'));
    }
};
