<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plugin_operations')) {
            return;
        }

        Schema::create('plugin_operations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 64)->unique();
            $table->string('type', 32)->index();
            $table->string('plugin_name', 100)->index();
            $table->string('version', 100)->nullable();
            $table->text('release_url')->nullable();
            $table->string('upload_path')->nullable();
            $table->string('status', 32)->index();
            $table->string('stage', 64)->index();
            $table->text('message')->nullable();
            $table->text('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('run_token', 64)->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['plugin_name', 'status']);
            $table->index(['status', 'updated_at']);
            $table->index(['admin_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_operations');
    }
};
