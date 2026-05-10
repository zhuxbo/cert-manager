<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agisos')) {
            Schema::create('agisos', function (Blueprint $table) {
                $table->id();
                $table->string('pay_method', 50)->nullable();
                $table->string('sign', 100)->nullable();
                $table->integer('type')->nullable();
                $table->text('data')->nullable();
                $table->string('tid', 100)->nullable()->index();
                $table->string('refund_id', 100)->nullable();
                $table->string('status', 50)->nullable();
                $table->string('product_code', 100)->nullable();
                $table->integer('period')->nullable();
                $table->decimal('price', 10, 2)->default(0);
                $table->integer('count')->default(1);
                $table->decimal('amount', 10, 2)->default(0);
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->tinyInteger('recharged')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('easy_logs')) {
            Schema::create('easy_logs', function (Blueprint $table) {
                $table->id();
                $table->string('method', 10)->nullable();
                $table->text('url')->nullable();
                $table->text('params')->nullable();
                $table->text('response')->nullable();
                $table->string('ip', 50)->nullable();
                $table->tinyInteger('status')->default(0);
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('easy_logs');
        Schema::dropIfExists('agisos');
    }
};
