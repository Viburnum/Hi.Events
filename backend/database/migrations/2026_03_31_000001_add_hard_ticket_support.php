<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', static function (Blueprint $table) {
            $table->boolean('is_hard_ticket')->default(false);
            $table->decimal('hard_ticket_fee', 14, 2)->nullable();
        });

        Schema::table('attendees', static function (Blueprint $table) {
            $table->string('fulfillment_status')->nullable();
        });

        Schema::table('orders', static function (Blueprint $table) {
            $table->string('fulfillment_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', static function (Blueprint $table) {
            $table->dropColumn(['is_hard_ticket', 'hard_ticket_fee']);
        });

        Schema::table('attendees', static function (Blueprint $table) {
            $table->dropColumn('fulfillment_status');
        });

        Schema::table('orders', static function (Blueprint $table) {
            $table->dropColumn('fulfillment_status');
        });
    }
};
