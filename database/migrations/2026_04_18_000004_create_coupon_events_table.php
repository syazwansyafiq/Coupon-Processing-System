<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_events', function (Blueprint $table) {
            $table->id();
            $table->enum('event_type', [
                'applied',
                'validation_passed',
                'validation_failed',
                'reserved',
                'consumed',
                'released',
                'expired',
            ]);
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cart_id', 128)->nullable();
            $table->string('order_id', 128)->nullable();
            $table->string('idempotency_key', 255)->nullable()->index();
            $table->unsignedInteger('rule_version')->nullable();
            // Full snapshot of cart context and rules at time of event
            $table->json('payload');
            $table->timestamps();

            $table->index(['coupon_id', 'event_type']);
            $table->index(['user_id', 'event_type']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_events');
    }
};
