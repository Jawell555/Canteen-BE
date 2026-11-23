<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Order;
use App\Services\StockHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffOrderController extends Controller
{
    public function __construct(
        protected StockHoldService $stockHoldService
    ) {}

    /**
     * Get live filtered orders for kitchen fulfillment queue.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['orderItems.menuItem', 'paymentRecord', 'stockHolds'])->latest();

        if ($request->has('status') && ! empty($request->query('status'))) {
            $statuses = explode(',', $request->query('status'));
            $query->whereIn('status', $statuses);
        }

        $orders = $query->get()->map(function ($order) {
            $activeHold = $order->stockHolds->where('status', 'active')->first();
            $holdSeconds = $activeHold ? max(0, now()->diffInSeconds($activeHold->expires_at, false)) : 0;

            $items = $order->orderItems->map(function ($oi) {
                return [
                    'id' => $oi->menu_item_id,
                    'name' => $oi->item_name ?: $oi->menuItem?->name ?: 'Item',
                    'quantity' => $oi->quantity,
                    'unit_price' => (float) $oi->unit_price,
                    'subtotal' => (float) $oi->subtotal,
                    'custom_notes' => $oi->custom_notes ?: '',
                ];
            });

            $payment = $order->paymentRecord ? [
                'method' => $order->paymentRecord->method,
                'status' => $order->paymentRecord->status,
                'amount' => (float) ($order->paymentRecord->amount ?: $order->total_amount),
                'reference_number' => $order->paymentRecord->reference_number,
                'proof_image_url' => $order->paymentRecord->proof_image_url,
            ] : [
                'method' => 'counter_cash',
                'status' => 'unverified',
                'amount' => (float) $order->total_amount,
                'reference_number' => null,
                'proof_image_url' => null,
            ];

            return [
                'id' => $order->id,
                'order_uuid' => $order->uuid,
                'queue_number' => $order->formatted_queue_number,
                'daily_queue_number' => $order->daily_queue_number,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'status' => $order->status,
                'created_at' => $order->created_at ? $order->created_at->format('h:i A') : '',
                'hold_seconds_remaining' => $holdSeconds,
                'total_amount' => (float) $order->total_amount,
                'payment' => $payment,
                'items' => $items,
            ];
        });

        return ApiResponse::success([
            'orders' => $orders,
        ], 'Staff orders retrieved successfully');
    }

    /**
     * Advance order status: pending -> preparing -> ready -> completed
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:pending,preparing,ready,completed,cancelled',
        ]);

        $order = Order::where('uuid', $id)->orWhere('id', is_numeric($id) ? (int) $id : 0)->firstOrFail();
        $order->status = $validated['status'];

        if ($validated['status'] === 'completed') {
            $order->completed_at = now();
        }

        $order->save();

        return ApiResponse::success($order, 'Order status updated to '.$validated['status']);
    }

    /**
     * Verify GCash proof or Cash payment, commit stock holds.
     */
    public function verifyPayment(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|string|in:verify,reject',
            'reason' => 'nullable|string|max:500',
        ]);

        $order = Order::with(['paymentRecord', 'stockHolds'])->where('uuid', $id)->orWhere('id', is_numeric($id) ? (int) $id : 0)->firstOrFail();

        if ($validated['action'] === 'verify') {
            if ($order->paymentRecord) {
                $order->paymentRecord->status = 'verified';
                $order->paymentRecord->verified_by_user_id = $request->user()?->id;
                $order->paymentRecord->verified_at = now();
                $order->paymentRecord->save();
            }

            if ($order->status === 'pending') {
                $order->status = 'preparing';
                $order->save();
            }

            // Commit stock holds permanently
            $this->stockHoldService->commitHolds($order);

            return ApiResponse::success($order, 'Payment verified and stock holds committed');
        } else {
            // Reject payment & release stock
            if ($order->paymentRecord) {
                $order->paymentRecord->status = 'rejected';
                $order->paymentRecord->rejection_reason = $validated['reason'] ?? 'Payment rejected by staff';
                $order->paymentRecord->save();
            }

            $order->status = 'cancelled';
            $order->cancellation_reason = $validated['reason'] ?? 'Payment rejected';
            $order->save();

            $this->stockHoldService->releaseOrderHolds($order);

            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'reject_payment',
                'entity_type' => 'orders',
                'entity_id' => (string) $order->id,
                'details' => ['reason' => $validated['reason'] ?? 'Payment rejected'],
                'ip_address' => $request->ip(),
            ]);

            return ApiResponse::success($order, 'Payment rejected and stock holds released');
        }
    }

    /**
     * Cancel order with mandatory reason and return inventory.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $order = Order::where('uuid', $id)->orWhere('id', is_numeric($id) ? (int) $id : 0)->firstOrFail();

        $order->status = 'cancelled';
        $order->cancellation_reason = $validated['reason'];
        $order->save();

        $this->stockHoldService->releaseOrderHolds($order);

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'cancel_order',
            'entity_type' => 'orders',
            'entity_id' => (string) $order->id,
            'details' => ['reason' => $validated['reason']],
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success($order, 'Order cancelled and inventory restored');
    }
}
