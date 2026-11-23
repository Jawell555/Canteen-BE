<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentRecord;
use App\Models\StockHold;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAndAdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $staffUser;

    protected User $customerUser;

    protected MenuItem $menuItem;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::create([
            'name' => 'Admin Boss',
            'email' => 'admin@test.local',
            'password' => bcrypt('secret123'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->staffUser = User::create([
            'name' => 'Staff Cook',
            'email' => 'staff@test.local',
            'password' => bcrypt('secret123'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->customerUser = User::create([
            'name' => 'Student Juan',
            'email' => 'student@test.local',
            'password' => bcrypt('secret123'),
            'role' => 'customer',
            'is_active' => true,
        ]);

        $this->category = Category::create([
            'name' => 'Meals',
            'slug' => 'meals',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $this->menuItem = MenuItem::create([
            'category_id' => $this->category->id,
            'name' => 'Chicken Adobo with Rice',
            'description' => 'Classic savory chicken',
            'price' => 85.00,
            'daily_stock_limit' => 20,
            'current_stock' => 15,
            'is_sold_out' => false,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_staff_or_admin_routes(): void
    {
        $this->getJson('/api/v1/staff/orders')->assertStatus(401);
        $this->getJson('/api/v1/admin/menu')->assertStatus(401);
    }

    public function test_customer_cannot_access_staff_or_admin_routes(): void
    {
        $this->actingAs($this->customerUser)
            ->getJson('/api/v1/staff/orders')
            ->assertStatus(403);

        $this->actingAs($this->customerUser)
            ->getJson('/api/v1/admin/menu')
            ->assertStatus(403);
    }

    public function test_staff_cannot_access_admin_routes(): void
    {
        $this->actingAs($this->staffUser)
            ->getJson('/api/v1/admin/menu')
            ->assertStatus(403);

        $this->actingAs($this->staffUser)
            ->getJson('/api/v1/admin/staff')
            ->assertStatus(403);
    }

    public function test_staff_can_view_orders_queue(): void
    {
        $order = Order::create([
            'daily_queue_number' => 1,
            'order_date' => now()->toDateString(),
            'customer_name' => 'Maria Santos',
            'customer_phone' => '09123456789',
            'status' => 'pending',
            'subtotal' => 85.00,
            'total_amount' => 85.00,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'menu_item_id' => $this->menuItem->id,
            'item_name' => $this->menuItem->name,
            'quantity' => 1,
            'unit_price' => 85.00,
            'subtotal' => 85.00,
        ]);

        PaymentRecord::create([
            'order_id' => $order->id,
            'method' => 'gcash',
            'amount' => 85.00,
            'status' => 'unverified',
            'reference_number' => '123456789012',
        ]);

        $response = $this->actingAs($this->staffUser)
            ->getJson('/api/v1/staff/orders');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.orders');
    }

    public function test_staff_can_update_order_status_progression(): void
    {
        $order = Order::create([
            'daily_queue_number' => 2,
            'order_date' => now()->toDateString(),
            'customer_name' => 'Pedro Penduko',
            'customer_phone' => '09123456789',
            'status' => 'pending',
            'subtotal' => 85.00,
            'total_amount' => 85.00,
        ]);

        // Advance to preparing
        $response = $this->actingAs($this->staffUser)
            ->patchJson("/api/v1/staff/orders/{$order->uuid}/status", [
                'status' => 'preparing',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'preparing');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'preparing',
        ]);

        // Advance to ready
        $this->actingAs($this->staffUser)
            ->patchJson("/api/v1/staff/orders/{$order->uuid}/status", [
                'status' => 'ready',
            ])->assertStatus(200);

        // Advance to completed
        $this->actingAs($this->staffUser)
            ->patchJson("/api/v1/staff/orders/{$order->uuid}/status", [
                'status' => 'completed',
            ])->assertStatus(200);

        $this->assertNotNull($order->fresh()->completed_at);
    }

    public function test_staff_can_verify_payment_and_commit_stock(): void
    {
        $order = Order::create([
            'daily_queue_number' => 3,
            'order_date' => now()->toDateString(),
            'customer_name' => 'Ana Reyes',
            'customer_phone' => '09123456789',
            'status' => 'pending',
            'subtotal' => 85.00,
            'total_amount' => 85.00,
        ]);

        $payment = PaymentRecord::create([
            'order_id' => $order->id,
            'method' => 'gcash',
            'amount' => 85.00,
            'status' => 'unverified',
            'reference_number' => '987654321098',
        ]);

        StockHold::create([
            'order_id' => $order->id,
            'menu_item_id' => $this->menuItem->id,
            'quantity' => 1,
            'status' => 'active',
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->actingAs($this->staffUser)
            ->postJson("/api/v1/staff/orders/{$order->uuid}/verify-payment", [
                'action' => 'verify',
            ]);

        $response->assertStatus(200);

        $this->assertEquals('verified', $payment->fresh()->status);
        $this->assertEquals('preparing', $order->fresh()->status);
        $this->assertDatabaseHas('stock_holds', [
            'order_id' => $order->id,
            'status' => 'committed',
        ]);
    }

    public function test_staff_can_cancel_order_and_release_stock(): void
    {
        $initialStock = $this->menuItem->current_stock;

        $order = Order::create([
            'daily_queue_number' => 4,
            'order_date' => now()->toDateString(),
            'customer_name' => 'Mark Cruz',
            'customer_phone' => '09123456789',
            'status' => 'pending',
            'subtotal' => 85.00,
            'total_amount' => 85.00,
        ]);

        StockHold::create([
            'order_id' => $order->id,
            'menu_item_id' => $this->menuItem->id,
            'quantity' => 2,
            'status' => 'active',
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->actingAs($this->staffUser)
            ->postJson("/api/v1/staff/orders/{$order->uuid}/cancel", [
                'reason' => 'Customer requested cancellation at counter',
            ]);

        $response->assertStatus(200);

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals('Customer requested cancellation at counter', $order->fresh()->cancellation_reason);
        $this->assertEquals($initialStock + 2, $this->menuItem->fresh()->current_stock);
    }

    public function test_staff_can_toggle_menu_sold_out(): void
    {
        $this->assertFalse((bool) $this->menuItem->is_sold_out);

        $response = $this->actingAs($this->staffUser)
            ->patchJson("/api/v1/staff/menu/{$this->menuItem->id}/toggle-sold-out", [
                'is_sold_out' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_sold_out', true);

        $this->assertTrue((bool) $this->menuItem->fresh()->is_sold_out);
    }

    public function test_admin_can_crud_menu_items(): void
    {
        // 1. List menu items
        $this->actingAs($this->adminUser)
            ->getJson('/api/v1/admin/menu')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        // 2. Create menu item
        $createResponse = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/admin/menu', [
                'name' => 'Crispy Sisig with Egg',
                'category' => 'Meals',
                'price' => 110.00,
                'daily_stock_limit' => 30,
                'description' => 'Sizzling pork sisig',
            ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('data.name', 'Crispy Sisig with Egg');

        $itemId = $createResponse->json('data.id');

        // 3. Update menu item
        $this->actingAs($this->adminUser)
            ->putJson("/api/v1/admin/menu/{$itemId}", [
                'name' => 'Crispy Sisig with Egg (Special)',
                'price' => 120.00,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.price', '120.00');

        // 4. Delete menu item
        $this->actingAs($this->adminUser)
            ->deleteJson("/api/v1/admin/menu/{$itemId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('menu_items', ['id' => $itemId]);
    }

    public function test_admin_can_manage_staff_accounts(): void
    {
        // 1. List staff
        $this->actingAs($this->adminUser)
            ->getJson('/api/v1/admin/staff')
            ->assertStatus(200);

        // 2. Create staff
        $createResponse = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/admin/staff', [
                'name' => 'Kitchen Assistant Dan',
                'email' => 'dan.kitchen@test.local',
                'password' => 'secret123',
                'role' => 'staff',
            ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('data.name', 'Kitchen Assistant Dan');

        $staffId = $createResponse->json('data.id');

        // 3. Toggle active
        $this->actingAs($this->adminUser)
            ->patchJson("/api/v1/admin/staff/{$staffId}/toggle-active")
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_admin_can_view_sales_report_and_audit_logs(): void
    {
        // Seed a completed order
        $order = Order::create([
            'daily_queue_number' => 5,
            'order_date' => now()->toDateString(),
            'customer_name' => 'Sarah Geronimo',
            'customer_phone' => '09123456789',
            'status' => 'completed',
            'subtotal' => 170.00,
            'total_amount' => 170.00,
            'completed_at' => now(),
        ]);

        PaymentRecord::create([
            'order_id' => $order->id,
            'method' => 'counter_cash',
            'amount' => 170.00,
            'status' => 'verified',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'menu_item_id' => $this->menuItem->id,
            'item_name' => $this->menuItem->name,
            'quantity' => 2,
            'unit_price' => 85.00,
            'subtotal' => 170.00,
        ]);

        // Sales report
        $reportResponse = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/admin/reports/sales?period=daily');

        $reportResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.metrics.total_orders_completed', 1)
            ->assertJsonPath('data.metrics.total_revenue', 170);

        // Audit logs
        $logsResponse = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/admin/audit-logs');

        $logsResponse->assertStatus(200)
            ->assertJsonPath('success', true);
    }
}
