<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected Order $completedOrder;

    protected Order $pendingOrder;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('feedback')) {
            Schema::create('feedback', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->smallInteger('rating');
                $table->text('comments')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        $category = Category::create([
            'name' => 'Meals',
            'slug' => 'meals',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $menuItem = MenuItem::create([
            'category_id' => $category->id,
            'name' => 'Chicken Inasal',
            'description' => 'Grilled chicken marinated in calamansi and annatto',
            'price' => 120.00,
            'daily_stock_limit' => 50,
            'current_stock' => 50,
            'is_sold_out' => false,
        ]);

        $this->completedOrder = Order::create([
            'uuid' => (string) Str::uuid(),
            'daily_queue_number' => 42,
            'order_date' => now()->toDateString(),
            'customer_name' => 'Guest Juan',
            'customer_phone' => '09171234567',
            'guest_token' => 'guest_token_test_abc',
            'status' => 'completed',
            'completed_at' => now(),
            'subtotal' => 120.00,
            'total_amount' => 120.00,
        ]);

        OrderItem::create([
            'order_id' => $this->completedOrder->id,
            'menu_item_id' => $menuItem->id,
            'item_name' => $menuItem->name,
            'unit_price' => $menuItem->price,
            'quantity' => 1,
            'subtotal' => 120.00,
            'custom_notes' => 'Extra garlic rice please',
        ]);

        $this->pendingOrder = Order::create([
            'uuid' => (string) Str::uuid(),
            'daily_queue_number' => 43,
            'order_date' => now()->toDateString(),
            'customer_name' => 'Guest Maria',
            'customer_phone' => '09181234567',
            'guest_token' => 'guest_token_test_xyz',
            'status' => 'pending',
            'subtotal' => 120.00,
            'total_amount' => 120.00,
        ]);
    }

    public function test_guest_can_submit_feedback_for_a_completed_order(): void
    {
        $payload = [
            'order_id' => $this->completedOrder->uuid,
            'rating' => 5,
            'comment' => 'Delicious chicken inasal! Handover was swift.',
        ];

        $response = $this->postJson('/api/v1/feedback', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.order_id', $this->completedOrder->id)
            ->assertJsonPath('data.comments', 'Delicious chicken inasal! Handover was swift.');

        $this->assertDatabaseHas('feedback', [
            'order_id' => $this->completedOrder->id,
            'rating' => 5,
            'comments' => 'Delicious chicken inasal! Handover was swift.',
        ]);

        // Verify summary calculation
        $summaryResponse = $this->getJson('/api/v1/feedback/summary');
        $summaryResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_reviews', 1)
            ->assertJsonPath('data.average_rating', 5);
    }

    public function test_duplicate_feedback_on_same_order_is_rejected(): void
    {
        // First submission succeeds
        $firstResponse = $this->postJson('/api/v1/feedback', [
            'order_id' => $this->completedOrder->id,
            'rating' => 4,
            'comments' => 'First review',
        ]);
        $firstResponse->assertStatus(200);

        // Second submission using order UUID
        $duplicateResponse = $this->postJson('/api/v1/feedback', [
            'order_id' => $this->completedOrder->uuid,
            'rating' => 5,
            'comments' => 'Duplicate attempt review',
        ]);

        $duplicateResponse->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('feedback', 1);
    }

    public function test_feedback_rating_must_be_between_1_and_5(): void
    {
        // Rating below 1
        $lowResponse = $this->postJson('/api/v1/feedback', [
            'order_id' => $this->completedOrder->id,
            'rating' => 0,
            'comments' => 'Too low',
        ]);
        $lowResponse->assertStatus(422)
            ->assertJsonPath('success', false);

        // Rating above 5
        $highResponse = $this->postJson('/api/v1/feedback', [
            'order_id' => $this->completedOrder->id,
            'rating' => 6,
            'comments' => 'Too high',
        ]);
        $highResponse->assertStatus(422)
            ->assertJsonPath('success', false);

        // Feedback on pending order rejected
        $pendingResponse = $this->postJson('/api/v1/feedback', [
            'order_id' => $this->pendingOrder->id,
            'rating' => 5,
        ]);
        $pendingResponse->assertStatus(422)
            ->assertJsonPath('success', false);

        // Feedback on non-existent order rejected with 404
        $notFoundResponse = $this->postJson('/api/v1/feedback', [
            'order_id' => 999999,
            'rating' => 5,
        ]);
        $notFoundResponse->assertStatus(404)
            ->assertJsonPath('success', false);
    }
}
