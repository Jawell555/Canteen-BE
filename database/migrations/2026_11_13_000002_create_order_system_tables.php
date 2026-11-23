<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            Schema::create('menu_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
                $table->string('name');
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2)->default(0.00);
                $table->string('image_url')->nullable();
                $table->integer('daily_stock_limit')->default(50);
                $table->integer('current_stock')->default(50);
                $table->boolean('is_sold_out')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->integer('daily_queue_number')->index();
                $table->date('order_date')->index();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('customer_name');
                $table->string('customer_phone')->nullable();
                $table->string('guest_token')->nullable()->index();
                $table->string('status')->default('pending')->index(); // pending, preparing, ready, completed, cancelled
                $table->string('cancellation_reason')->nullable();
                $table->decimal('subtotal', 10, 2)->default(0.00);
                $table->decimal('total_amount', 10, 2)->default(0.00);
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('order_items')) {
            Schema::create('order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
                $table->string('item_name')->nullable();
                $table->integer('quantity');
                $table->decimal('unit_price', 10, 2);
                $table->text('custom_notes')->nullable();
                $table->decimal('subtotal', 10, 2);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payment_records')) {
            Schema::create('payment_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->string('method'); // gcash, counter_cash
                $table->decimal('amount', 10, 2)->default(0.00);
                $table->string('status')->default('unverified'); // unverified, verified, rejected
                $table->string('reference_number')->nullable();
                $table->string('proof_image_url')->nullable();
                $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('stock_holds')) {
            Schema::create('stock_holds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
                $table->integer('quantity');
                $table->timestamp('expires_at')->index();
                $table->string('status')->default('active')->index(); // active, committed, released
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_holds');
        Schema::dropIfExists('payment_records');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('menu_items');
    }
};
