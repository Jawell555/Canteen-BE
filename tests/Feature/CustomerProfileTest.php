<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentRecord;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerProfileTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected MenuItem $menuItem;

    protected Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('favorites')) {
            Schema::create('favorites', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['user_id', 'menu_item_id'], 'uq_user_favorite');
            });
        }

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

        $this->customer = User::create([
            'name' => 'Juan Dela Cruz',
            'email' => 'juan.delacruz@campus.edu.ph',
            'password' => bcrypt('password123'),
            'role' => 'customer',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Meals',
            'slug' => 'meals',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $this->menuItem = MenuItem::create([
            'category_id' => $category->id,
            'name' => 'Tapsilog',
            'description' => 'Tender beef tapa with garlic fried rice and sunny egg',
            'price' => 95.00,
            'daily_stock_limit' => 50,
            'current_stock' => 50,
            'is_sold_out' => false,
        ]);

        $this->order = Order::create([
            'uuid' => (string) Str::uuid(),
            'daily_queue_number' => 12,
            'order_date' => now()->toDateString(),
            'user_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'customer_phone' => '09170001122',
            'status' => 'completed',
            'completed_at' => now(),
            'subtotal' => 95.00,
            'total_amount' => 95.00,
        ]);

        OrderItem::create([
            'order_id' => $this->order->id,
            'menu_item_id' => $this->menuItem->id,
            'item_name' => $this->menuItem->name,
            'unit_price' => $this->menuItem->price,
            'quantity' => 1,
            'subtotal' => 95.00,
            'custom_notes' => 'Egg runny please',
        ]);

        PaymentRecord::create([
            'order_id' => $this->order->id,
            'method' => 'gcash',
            'amount' => 95.00,
            'status' => 'verified',
            'reference_number' => 'GCASH987654321',
        ]);
    }

    public function test_authenticated_customer_can_retrieve_order_history(): void
    {
        $response = $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/customer/orders');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.orders.0.order_uuid', $this->order->uuid)
            ->assertJsonPath('data.orders.0.queue_number', '#012')
            ->assertJsonPath('data.orders.0.status', 'completed')
            ->assertJsonPath('data.orders.0.total', 95)
            ->assertJsonPath('data.orders.0.payment_method', 'GCash')
            ->assertJsonPath('data.orders.0.has_feedback', false)
            ->assertJsonPath('data.orders.0.items.0.name', 'Tapsilog')
            ->assertJsonCount(1, 'data.orders');
    }

    public function test_authenticated_customer_can_toggle_favorite_items(): void
    {
        // 1. Star the item
        $toggleOnResponse = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/customer/favorites/toggle', [
                'menu_item_id' => $this->menuItem->id,
            ]);

        $toggleOnResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_favorited', true)
            ->assertJsonPath('data.action', 'added');

        $this->assertDatabaseHas('favorites', [
            'user_id' => $this->customer->id,
            'menu_item_id' => $this->menuItem->id,
        ]);

        // 2. Retrieve favorites list
        $favoritesResponse = $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/customer/favorites');

        $favoritesResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.favorites.0.id', $this->menuItem->id)
            ->assertJsonPath('data.favorites.0.name', 'Tapsilog')
            ->assertJsonCount(1, 'data.favorites');

        // 3. Un-star the item
        $toggleOffResponse = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/customer/favorites/toggle', [
                'menu_item_id' => $this->menuItem->id,
            ]);

        $toggleOffResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_favorited', false)
            ->assertJsonPath('data.action', 'removed');

        $this->assertDatabaseMissing('favorites', [
            'user_id' => $this->customer->id,
            'menu_item_id' => $this->menuItem->id,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_profile_routes(): void
    {
        $this->getJson('/api/v1/customer/orders')
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $this->getJson('/api/v1/customer/favorites')
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $this->postJson('/api/v1/customer/favorites/toggle', [
            'menu_item_id' => $this->menuItem->id,
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }
}
