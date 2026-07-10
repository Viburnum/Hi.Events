<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('paypal_payments', static function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('paypal_order_id')->nullable();
            $table->string('capture_id')->nullable();
            $table->string('status')->nullable();
            $table->integer('amount_received')->nullable();
            $table->string('currency')->nullable();
            $table->json('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_payments');
    }
};
