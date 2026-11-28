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
     * Sales analytics and financial reporting aggregated in SQL for O(1) PHP memory usage.
     */
    public function sales(Request $request): JsonResponse
    {
        $period = strtolower((string) $request->query('period', 'daily'));
        $startDate = null;
        $exactDate = null;

        if (in_array($period, ['day', 'daily', 'today'], true)) {
            $exactDate = Carbon::today()->toDateString();
            $periodLabel = Carbon::today()->format('M d, Y');
        } elseif (in_array($period, ['week', 'weekly'], true)) {
            $startDate = Carbon::today()->subDays(7)->toDateString();
            $periodLabel = 'Past 7 Days';
        } elseif (in_array($period, ['month', 'monthly'], true)) {
            $startDate = Carbon::today()->subDays(30)->toDateString();
            $periodLabel = 'Past 30 Days';
        } else {
            $periodLabel = 'All Time';
        }

        $applyDateFilter = function ($query, string $column = 'order_date') use ($exactDate, $startDate) {
            if ($exactDate !== null) {
                $query->whereDate($column, $exactDate);
            } elseif ($startDate !== null) {
                $query->where($column, '>=', $startDate);
            }
        };

        // 1. Order status aggregates in SQL
        $orderStatsQuery = Order::query()
            ->selectRaw('status, COUNT(*) as order_count, COALESCE(SUM(total_amount), 0) as total_revenue')
            ->groupBy('status');
        $applyDateFilter($orderStatsQuery, 'order_date');
        $orderStats = $orderStatsQuery->get()->keyBy('status');

        $completedStat = $orderStats->get('completed');
        $cancelledStat = $orderStats->get('cancelled');

        $completedCount = $completedStat ? (int) $completedStat->order_count : 0;
        $cancelledCount = $cancelledStat ? (int) $cancelledStat->order_count : 0;
        $totalRevenue = $completedStat ? (float) $completedStat->total_revenue : 0.0;
        $aov = $completedCount > 0 ? round($totalRevenue / $completedCount, 2) : 0.00;

        // 2. Payment split aggregated in SQL
        $paymentStatsQuery = PaymentRecord::query()
            ->join('orders', 'orders.id', '=', 'payment_records.order_id')
            ->where('orders.status', 'completed')
            ->selectRaw('payment_records.method, COUNT(*) as pay_count, COALESCE(SUM(payment_records.amount), 0) as pay_amount')
            ->groupBy('payment_records.method');
        $applyDateFilter($paymentStatsQuery, 'orders.order_date');
        $paymentStats = $paymentStatsQuery->get()->keyBy('method');

        $gcashRow = $paymentStats->get('gcash');
        $cashRow = $paymentStats->get('counter_cash');

        $gcashAmount = $gcashRow ? (float) $gcashRow->pay_amount : 0.0;
        $gcashCount = $gcashRow ? (int) $gcashRow->pay_count : 0;
        $cashAmount = $cashRow ? (float) $cashRow->pay_amount : 0.0;
        $cashCount = $cashRow ? (int) $cashRow->pay_count : 0;
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

        // 3. Top 5 selling items aggregated in SQL
        $topSellingQuery = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('menu_items', 'menu_items.id', '=', 'order_items.menu_item_id')
            ->where('orders.status', 'completed')
            ->selectRaw('order_items.menu_item_id, MAX(COALESCE(order_items.item_name, menu_items.name, \'Item\')) as name, SUM(order_items.quantity) as quantity_sold, SUM(order_items.subtotal) as total_sales')
            ->groupBy('order_items.menu_item_id')
            ->orderByDesc('quantity_sold')
            ->limit(5);
        $applyDateFilter($topSellingQuery, 'orders.order_date');

        $topSelling = $topSellingQuery->get()->map(fn ($row) => [
            'menu_item_id' => $row->menu_item_id,
            'name' => $row->name,
            'quantity_sold' => (int) $row->quantity_sold,
            'total_sales' => (float) $row->total_sales,
        ])->values();

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
        $logs = AuditLog::with('user:id,name,role')->latest()->take(100)->get()->map(function ($log) {
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
