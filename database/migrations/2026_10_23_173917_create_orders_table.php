<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('customer_email');
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('INR');
            $table->string('status', 20)->default('pending');
            $table->string('payment_id')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
