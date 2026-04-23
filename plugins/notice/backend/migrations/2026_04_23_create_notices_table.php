<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notice_notices') && ! Schema::hasTable('notices')) {
            Schema::rename('notice_notices', 'notices');

            return;
        }

        if (Schema::hasTable('notices')) {
            return;
        }

        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('content');
            $table->string('type', 20)->default('info');
            $table->string('position', 20)->default('dashboard');
            $table->boolean('is_active')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
