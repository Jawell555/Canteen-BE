<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCartTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;

    protected MenuItem $adobo;

    protected MenuItem $sinigang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::create([
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@campus.edu.ph',
            'password' => bcrypt('password123'),
            'role' => 'customer',
            'is_active' => true,
        ]);

        $meals = Category::create([
            'name' => 'Meals',
            'slug' => 'meals',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $this->adobo = MenuItem::create([
            'category_id' => $meals->id,
            'name' => 'Chicken Adobo with Rice',
            'description' => 'Savory chicken adobo',
            'price' => 85.00,
            'daily_stock_limit' => 20,
            'current_stock' => 5,
            'is_sold_out' => false,
        ]);

        $this->sinigang = MenuItem::create([
            'category_id' => $meals->id,
            'name' => 'Pork Sinigang with Rice',
            'description' => 'Sour tamarind soup',
            'price' => 95.00,
            'daily_stock_limit' => 20,
            'current_stock' => 0,
            'is_sold_out' => true,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_customer_cart(): void
    {
        $this->getJson('/api/v1/customer/cart')->assertStatus(401);
        $this->postJson('/api/v1/customer/cart/items', [
            'menu_item_id' => $this->adobo->id,
            'quantity' => 1,
        ])->assertStatus(401);
    }

    public function test_customer_can_add_update_remove_and_persist_cart_items(): void
    {
        $add1 = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/customer/cart/items', [
                'menu_item_id' => $this->adobo->id,
                'quantity' => 2,
                'custom_notes' => 'Less rice',
            ]);

        $add1->assertStatus(200)
            ->assertJsonPath('data.item_count', 2)
            ->assertJsonPath('data.subtotal', 170)
            ->assertJsonCount(1, 'data.items');

        // Same item + same notes increments quantity
        $add2 = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/customer/cart/items', [
                'menu_item_id' => $this->adobo->id,
                'quantity' => 1,
                'custom_notes' => 'Less rice',
            ]);

        $add2->assertStatus(200)
            ->assertJsonPath('data.item_count', 3)
            ->assertJsonCount(1, 'data.items');

        // Same item + different notes creates a separate cart line
        $add3 = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/customer/cart/items', [
                'menu_item_id' => $this->adobo->id,
                'quantity' => 1,
                'custom_notes' => 'Extra sauce',
            ]);

        $add3->assertStatus(200)
            ->assertJsonPath('data.item_count', 4)
            ->assertJsonCount(2, 'data.items');

        $firstCartItemId = $add3->json('data.items.0.id');

        // Update quantity
        $updateRes = $this->actingAs($this->customer, 'sanctum')
            ->patchJson("/api/v1/customer/cart/items/{$firstCartItemId}", [
                'quantity' => 2,
            ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('data.item_count', 3)
            ->assertJsonPath('data.subtotal', 255);

        // Fetching cart returns persisted items
        $getRes = $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/customer/cart');

        $getRes->assertStatus(200)
            ->assertJsonPath('data.item_count', 3)
            ->assertJsonCount(2, 'data.items');

        // Remove single item
        $deleteRes = $this->actingAs($this->customer, 'sanctum')
            ->deleteJson("/api/v1/customer/cart/items/{$firstCartItemId}");

        $deleteRes->assertStatus(200)
            ->assertJsonPath('data.item_count', 1)
            ->assertJsonCount(1, 'data.items');
    }

    public function test_cart_enforces_stock_limits_and_merges_guest_items(): void
    {
        // Sold out item is rejected
        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/customer/cart/items', [
                'menu_item_id' => $this->sinigang->id,
                'quantity' => 1,
            ])
            ->assertStatus(422);

        // Merge guest cart (skips sold-out item and caps stock at 5)
        $mergeRes = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/customer/cart/merge', [
                'items' => [
                    [
                        'menu_item_id' => $this->adobo->id,
                        'quantity' => 10,
                        'custom_notes' => '',
                    ],
                    [
                        'menu_item_id' => $this->sinigang->id,
                        'quantity' => 2,
                        'custom_notes' => '',
                    ],
                ],
            ]);

        $mergeRes->assertStatus(200)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 5)
            ->assertJsonPath('data.item_count', 5);
    }

    public function test_checkout_clears_authenticated_customer_persisted_cart(): void
    {
        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/customer/cart/items', [
                'menu_item_id' => $this->adobo->id,
                'quantity' => 2,
            ])
            ->assertStatus(200);

        $token = $this->customer->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/orders/checkout', [
                'customer_name' => $this->customer->name,
                'payment_method' => 'counter_cash',
                'items' => [
                    [
                        'menu_item_id' => $this->adobo->id,
                        'quantity' => 2,
                    ],
                ],
            ])
            ->assertStatus(201);

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/customer/cart')
            ->assertStatus(200)
            ->assertJsonPath('data.item_count', 0)
            ->assertJsonCount(0, 'data.items');
    }
}
