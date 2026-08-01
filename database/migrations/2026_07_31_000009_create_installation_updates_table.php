<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation_updates', function (Blueprint $table): void {
            $table->id();
            $table->string('installed_version', 32);
            $table->string('status', 24)->default('idle')->index();
            $table->string('available_version', 32)->nullable();
            $table->string('release_url', 2048)->nullable();
            $table->string('image_digest', 71)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_updates');
    }
};
