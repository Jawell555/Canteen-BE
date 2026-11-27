<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $meals = Category::create([
            'name' => 'Meals',
            'slug' => 'meals',
            'display_order' => 1,
            'is_active' => true,
        ]);

        MenuItem::create([
            'category_id' => $meals->id,
            'name' => 'Chicken Adobo with Rice',
            'description' => 'Classic savory chicken',
            'price' => 85.00,
            'daily_stock_limit' => 10,
            'current_stock' => 10,
            'is_sold_out' => false,
        ]);
    }

    public function test_menu_catalog_returns_categories_and_items(): void
    {
        $response = $this->getJson('/api/v1/menu/items');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items',
                    'grouped',
                ],
            ]);
    }

    public function test_guest_can_checkout_with_gcash_and_reserve_stock(): void
    {
        $menuItem = MenuItem::first();

        $response = $this->postJson('/api/v1/orders/checkout', [
            'customer_name' => 'Pedro Penduko',
            'customer_phone' => '09191234567',
            'payment_method' => 'gcash',
            'reference_number' => '9988776655443',
            'items' => [
                [
                    'menu_item_id' => $menuItem->id,
                    'quantity' => 3,
                    'custom_notes' => 'Extra spicy',
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.formatted_queue_number', '#001')
            ->assertJsonPath('data.daily_queue_number', 1)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'order',
                    'order_uuid',
                    'daily_queue_number',
                    'formatted_queue_number',
                    'hold_expires_at',
                    'hold_seconds_remaining',
                ],
            ]);

        // Stock should be decremented from 10 to 7
        $this->assertEquals(7, $menuItem->fresh()->current_stock);
    }

    public function test_checkout_fails_when_stock_insufficient(): void
    {
        $menuItem = MenuItem::first();

        $response = $this->postJson('/api/v1/orders/checkout', [
            'customer_name' => 'Greedy Buyer',
            'payment_method' => 'counter_cash',
            'items' => [
                [
                    'menu_item_id' => $menuItem->id,
                    'quantity' => 999, // Exceeds 10
                ],
            ],
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_daily_queue_number_increments_sequentially(): void
    {
        $menuItem = MenuItem::first();

        $res1 = $this->postJson('/api/v1/orders/checkout', [
            'customer_name' => 'Customer One',
            'payment_method' => 'counter_cash',
            'items' => [['menu_item_id' => $menuItem->id, 'quantity' => 1]],
        ]);
        $res1->assertStatus(201)->assertJsonPath('data.formatted_queue_number', '#001');

        $res2 = $this->postJson('/api/v1/orders/checkout', [
            'customer_name' => 'Customer Two',
            'payment_method' => 'counter_cash',
            'items' => [['menu_item_id' => $menuItem->id, 'quantity' => 1]],
        ]);
        $res2->assertStatus(201)->assertJsonPath('data.formatted_queue_number', '#002');
    }

    public function test_authenticated_customer_checkout_links_order_and_appears_in_history(): void
    {
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

        $customer = User::create([
            'name' => 'Maria Clara',
            'email' => 'maria@campus.edu.ph',
            'phone_number' => '09171112233',
            'password' => bcrypt('password123'),
            'role' => 'customer',
            'is_active' => true,
        ]);

        $token = $customer->createToken('auth_token')->plainTextToken;
        $menuItem = MenuItem::first();

        $checkoutRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/orders/checkout', [
                'customer_name' => $customer->name,
                'payment_method' => 'counter_cash',
                'items' => [
                    [
                        'menu_item_id' => $menuItem->id,
                        'quantity' => 2,
                        'custom_notes' => 'Less rice',
                    ],
                ],
            ]);

        $checkoutRes->assertStatus(201)
            ->assertJsonPath('data.order.user_id', $customer->id);

        $historyRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/customer/orders');

        $historyRes->assertStatus(200)
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.items.0.menu_item_id', $menuItem->id)
            ->assertJsonPath('data.orders.0.items.0.current_stock', 8)
            ->assertJsonPath('data.orders.0.items.0.is_available', true);
    }
}
