<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminReportController extends Controller
{
    /**
     * Sales analytics and financial reporting.
     */
    public function sales(Request $request): JsonResponse
    {
        $period = strtolower($request->query('period', 'daily'));

        $query = Order::query();

        if (in_array($period, ['day', 'daily', 'today'])) {
            $query->whereDate('order_date', Carbon::today()->toDateString());
            $periodLabel = Carbon::today()->format('M d, Y');
        } elseif (in_array($period, ['week', 'weekly'])) {
            $query->where('order_date', '>=', Carbon::today()->subDays(7)->toDateString());
            $periodLabel = 'Past 7 Days';
        } elseif (in_array($period, ['month', 'monthly'])) {
            $query->where('order_date', '>=', Carbon::today()->subDays(30)->toDateString());
            $periodLabel = 'Past 30 Days';
        } else {
            $periodLabel = 'All Time';
        }

        $allOrders = (clone $query)->get();
        $completedOrders = $allOrders->where('status', 'completed');
        $cancelledOrders = $allOrders->where('status', 'cancelled');

        $totalRevenue = (float) $completedOrders->sum('total_amount');
        $completedCount = $completedOrders->count();
        $cancelledCount = $cancelledOrders->count();
        $aov = $completedCount > 0 ? round($totalRevenue / $completedCount, 2) : 0.00;

        // Payment split from payment records
        $orderIds = $completedOrders->pluck('id');
        $completedPayments = PaymentRecord::whereIn('order_id', $orderIds)->get();

        $gcashPayments = $completedPayments->where('method', 'gcash');
        $cashPayments = $completedPayments->where('method', 'counter_cash');

        $gcashAmount = (float) $gcashPayments->sum('amount');
        $gcashCount = $gcashPayments->count();
        $cashAmount = (float) $cashPayments->sum('amount');
        $cashCount = $cashPayments->count();
        $totalPaymentCount = $gcashCount + $cashCount;

        $paymentSplit = [
            'gcash' => [
                'amount' => $gcashAmount,
                'count' => $gcashCount,
                'percentage' => $totalPaymentCount > 0 ? round(($gcashCount / $totalPaymentCount) * 100, 1) : 0,
            ],
            'counter_cash' => [
                'amount' => $cashAmount,
                'count' => $cashCount,
                'percentage' => $totalPaymentCount > 0 ? round(($cashCount / $totalPaymentCount) * 100, 1) : 0,
            ],
        ];

        // Top-selling items
        $orderItems = OrderItem::whereIn('order_id', $orderIds)
            ->with('menuItem')
            ->get();

        $topSelling = $orderItems->groupBy('menu_item_id')->map(function ($group, $itemId) {
            $name = $group->first()->item_name ?: $group->first()->menuItem?->name ?: 'Item';
            $qtySold = (int) $group->sum('quantity');
            $sales = (float) $group->sum('subtotal');

            return [
                'menu_item_id' => $itemId,
                'name' => $name,
                'quantity_sold' => $qtySold,
                'total_sales' => $sales,
            ];
        })->sortByDesc('quantity_sold')->values()->take(5);

        return ApiResponse::success([
            'date' => $periodLabel,
            'period' => $period,
            'metrics' => [
                'total_revenue' => $totalRevenue,
                'total_orders_completed' => $completedCount,
                'total_orders_cancelled' => $cancelledCount,
                'average_order_value' => $aov,
            ],
            'payment_split' => $paymentSplit,
            'top_selling_items' => $topSelling,
        ], 'Sales analytics report retrieved successfully');
    }

    /**
     * Chronological audit log of operations and cancellations.
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $logs = AuditLog::with('user')->latest()->take(100)->get()->map(function ($log) {
            return [
                'id' => $log->id,
                'action' => $log->action,
                'user_name' => $log->user ? $log->user->name.' ('.ucfirst($log->user->role).')' : 'System / Guest',
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'details' => is_array($log->details) ? json_encode($log->details) : ($log->details ?: ''),
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at ? $log->created_at->format('M d, Y • h:i A') : '',
            ];
        });

        return ApiResponse::success([
            'logs' => $logs,
        ], 'Audit logs retrieved successfully');
    }
}
