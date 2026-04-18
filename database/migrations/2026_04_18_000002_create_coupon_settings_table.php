<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('global_usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->decimal('min_cart_value', 10, 2)->nullable();
            // Flexible rule bag: first_time_user, categories, time_window, user_segments, product_ids
            $table->json('rules')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('activated_at')->useCurrent();
            $table->timestamps();

            $table->unique(['coupon_id', 'version']);
            $table->index(['coupon_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_settings');
    }
};
