<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentRecord;
use App\Services\QueueNumberService;
use App\Services\StockHoldService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrderController extends Controller
{
    public function __construct(
        protected StockHoldService $stockHoldService,
        protected QueueNumberService $queueNumberService
    ) {}

    /**
     * Checkout an order with 10-minute atomic stock reservation.
     */
    public function checkout(Request $request): JsonResponse
    {
        // Handle items whether passed as JSON array or JSON string in multipart form
        $rawItems = $request->input('items');
        if (is_string($rawItems)) {
            $decoded = json_decode($rawItems, true);
            if (is_array($decoded)) {
                $request->merge(['items' => $decoded]);
            }
        }

        $validated = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'nullable|string|max:30',
            'payment_method' => 'required|string|in:gcash,counter_cash',
            'reference_number' => 'nullable|string|max:100',
            'ref_no' => 'nullable|string|max:100',
            'proof_image' => 'nullable|image|max:10240', // Max 10MB
            'items' => 'required|array|min:1',
            'items.*.menu_item_id' => 'required_without:items.*.id|integer|exists:menu_items,id',
            'items.*.id' => 'sometimes|integer|exists:menu_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.custom_notes' => 'nullable|string|max:500',
        ]);

        $refNumber = $validated['reference_number'] ?? $validated['ref_no'] ?? null;
        if ($validated['payment_method'] === 'gcash' && empty($refNumber) && ! $request->hasFile('proof_image')) {
            return ApiResponse::error('GCash reference number or proof image is required.', 422, [
                'reference_number' => ['Please provide a GCash reference number or proof screenshot.'],
            ]);
        }

        try {
            $proofImageUrl = null;
            if ($request->hasFile('proof_image')) {
                $path = $request->file('proof_image')->store('proofs', 'public');
                $proofImageUrl = Storage::url($path);
            }

            $order = DB::transaction(function () use ($validated, $refNumber, $proofImageUrl, $request) {
                // 1. Calculate totals
                $subtotal = 0.00;
                $normalizedItems = [];

                foreach ($validated['items'] as $itemData) {
                    $menuItemId = $itemData['menu_item_id'] ?? $itemData['id'];
                    $menuItem = MenuItem::findOrFail($menuItemId);
                    $quantity = (int) $itemData['quantity'];
                    $itemSubtotal = $menuItem->price * $quantity;
                    $subtotal += $itemSubtotal;

                    $normalizedItems[] = [
                        'menu_item_id' => $menuItemId,
                        'menu_item' => $menuItem,
                        'item_name' => $menuItem->name,
                        'quantity' => $quantity,
                        'unit_price' => $menuItem->price,
                        'custom_notes' => $itemData['custom_notes'] ?? null,
                        'subtotal' => $itemSubtotal,
                    ];
                }

                $totalAmount = $subtotal;

                // 2. Generate daily sequential queue number
                $queueInfo = $this->queueNumberService->getNextQueueNumber();

                // 3. Create Order
                $order = Order::create([
                    'daily_queue_number' => $queueInfo['number'],
                    'order_date' => now()->toDateString(),
                    'user_id' => $request->user()?->id,
                    'customer_name' => $validated['customer_name'],
                    'customer_phone' => $validated['customer_phone'] ?? null,
                    'guest_token' => $request->header('X-Guest-Token'),
                    'status' => 'pending',
                    'subtotal' => $subtotal,
                    'total_amount' => $totalAmount,
                ]);

                // 4. Create OrderItems
                foreach ($normalizedItems as $item) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'menu_item_id' => $item['menu_item_id'],
                        'item_name' => $item['item_name'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'custom_notes' => $item['custom_notes'],
                        'subtotal' => $item['subtotal'],
                    ]);
                }

                // 5. Create PaymentRecord
                PaymentRecord::create([
                    'order_id' => $order->id,
                    'method' => $validated['payment_method'],
                    'amount' => $totalAmount,
                    'status' => 'unverified',
                    'reference_number' => $refNumber,
                    'proof_image_url' => $proofImageUrl,
                ]);

                // 6. Reserve stock with 10-minute hold
                $expiresAt = $this->stockHoldService->reserveStock($order, $normalizedItems, 10);

                return [$order, $expiresAt];
            });

            [$createdOrder, $expiresAt] = $order;
            $createdOrder->load(['orderItems.menuItem', 'paymentRecord']);

            return ApiResponse::success([
                'order' => $createdOrder,
                'order_uuid' => $createdOrder->uuid,
                'daily_queue_number' => $createdOrder->daily_queue_number,
                'formatted_queue_number' => $createdOrder->formatted_queue_number,
                'hold_expires_at' => $expiresAt->toIso8601ZuluString(),
                'hold_seconds_remaining' => 600,
            ], 'Order placed successfully. Stock reserved for 10 minutes.', 201);
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage() ?: 'Checkout failed', 400);
        }
    }

    /**
     * Show order details by UUID or ID.
     */
    public function show(string $id): JsonResponse
    {
        $order = Order::with(['orderItems.menuItem', 'paymentRecord'])
            ->where('uuid', $id)
            ->orWhere('id', is_numeric($id) ? (int) $id : 0)
            ->first();

        if (! $order) {
            return ApiResponse::error('Order not found', 404);
        }

        $hold = $order->stockHolds()->where('status', 'active')->first();
        $secondsRemaining = $hold ? max(0, now()->diffInSeconds($hold->expires_at, false)) : 0;

        return ApiResponse::success([
            'order' => $order,
            'queue_number' => $order->formatted_queue_number,
            'hold_seconds_remaining' => $secondsRemaining,
        ], 'Order details retrieved');
    }
}
