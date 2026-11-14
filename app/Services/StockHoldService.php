<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\StockHold;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;

class StockHoldService
{
    /**
     * Reserve stock for an order atomically using pessimistic locking.
     *
     * @param  array<int, array{menu_item_id: int, quantity: int}>  $items
     *
     * @throws Exception
     */
    public function reserveStock(Order $order, array $items, int $holdMinutes = 10): Carbon
    {
        $expiresAt = now()->addMinutes($holdMinutes);

        DB::transaction(function () use ($order, $items, $expiresAt) {
            foreach ($items as $item) {
                $itemId = $item['menu_item_id'];
                $quantity = (int) $item['quantity'];

                // Pessimistic lock
                $menuItem = MenuItem::where('id', $itemId)->lockForUpdate()->first();

                if (! $menuItem) {
                    throw new Exception("Menu item #{$itemId} not found.");
                }

                if ($menuItem->is_sold_out || $menuItem->current_stock < $quantity) {
                    throw new Exception("Insufficient stock for '{$menuItem->name}'. Only {$menuItem->current_stock} remaining.");
                }

                // Decrement stock
                $menuItem->current_stock -= $quantity;
                if ($menuItem->current_stock <= 0) {
                    $menuItem->is_sold_out = true;
                }
                $menuItem->save();

                // Create stock hold
                StockHold::create([
                    'order_id' => $order->id,
                    'menu_item_id' => $menuItem->id,
                    'quantity' => $quantity,
                    'expires_at' => $expiresAt,
                    'status' => 'active',
                ]);
            }
        });

        return $expiresAt;
    }

    /**
     * Commit holds when payment is verified.
     */
    public function commitHolds(Order $order): void
    {
        StockHold::where('order_id', $order->id)
            ->where('status', 'active')
            ->update(['status' => 'committed']);
    }

    /**
     * Release holds and return inventory for a given order.
     */
    public function releaseOrderHolds(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $holds = StockHold::where('order_id', $order->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            foreach ($holds as $hold) {
                $menuItem = MenuItem::where('id', $hold->menu_item_id)->lockForUpdate()->first();
                if ($menuItem) {
                    $menuItem->current_stock += $hold->quantity;
                    if ($menuItem->current_stock > 0 && $menuItem->is_sold_out) {
                        $menuItem->is_sold_out = false;
                    }
                    $menuItem->save();
                }

                $hold->status = 'released';
                $hold->save();
            }
        });
    }

    /**
     * Release all expired holds across the system and restore current_stock.
     */
    public function releaseExpiredHolds(): int
    {
        $releasedCount = 0;

        DB::transaction(function () use (&$releasedCount) {
            $expiredHolds = StockHold::where('status', 'active')
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->get();

            foreach ($expiredHolds as $hold) {
                $menuItem = MenuItem::where('id', $hold->menu_item_id)->lockForUpdate()->first();
                if ($menuItem) {
                    $menuItem->current_stock += $hold->quantity;
                    if ($menuItem->current_stock > 0 && $menuItem->is_sold_out) {
                        $menuItem->is_sold_out = false;
                    }
                    $menuItem->save();
                }

                $hold->status = 'released';
                $hold->save();
                $releasedCount++;

                // If the associated order is still pending, cancel it
                if ($hold->order && $hold->order->status === 'pending') {
                    $hold->order->status = 'cancelled';
                    $hold->order->save();
                }
            }
        });

        return $releasedCount;
    }
}
