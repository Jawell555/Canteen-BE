<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\StockHold;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockHoldService
{
    /**
     * Reserve stock for an order atomically using sorted pessimistic batch locking.
     *
     * @param  array<int, array{menu_item_id: int, quantity: int}>  $items
     *
     * @throws Exception
     */
    public function reserveStock(Order $order, array $items, int $holdMinutes = 10): Carbon
    {
        $expiresAt = now()->addMinutes($holdMinutes);
        $now = now();

        // Aggregate required quantities per menu_item_id and sort IDs to prevent lock deadlocks
        $qtyByMenuId = [];
        foreach ($items as $item) {
            $itemId = (int) $item['menu_item_id'];
            $qtyByMenuId[$itemId] = ($qtyByMenuId[$itemId] ?? 0) + (int) $item['quantity'];
        }
        ksort($qtyByMenuId);

        DB::transaction(function () use ($order, $items, $qtyByMenuId, $expiresAt, $now) {
            $menuItems = MenuItem::whereIn('id', array_keys($qtyByMenuId))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($qtyByMenuId as $itemId => $totalNeeded) {
                $menuItem = $menuItems->get($itemId);

                if (! $menuItem) {
                    throw new Exception("Menu item #{$itemId} not found.");
                }

                if ($menuItem->is_sold_out || $menuItem->current_stock < $totalNeeded) {
                    throw new Exception("Insufficient stock for '{$menuItem->name}'. Only {$menuItem->current_stock} remaining.");
                }

                $menuItem->current_stock -= $totalNeeded;
                if ($menuItem->current_stock <= 0) {
                    $menuItem->is_sold_out = true;
                }
                $menuItem->save();
            }

            $holdRows = [];
            foreach ($items as $item) {
                $holdRows[] = [
                    'order_id' => $order->id,
                    'menu_item_id' => (int) $item['menu_item_id'],
                    'quantity' => (int) $item['quantity'],
                    'expires_at' => $expiresAt,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($holdRows !== []) {
                StockHold::insert($holdRows);
            }

            DB::afterCommit(fn () => CanteenCache::forgetMenu());
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
            ->update(['status' => 'committed', 'updated_at' => now()]);
    }

    /**
     * Release holds and return inventory for a given order atomically.
     */
    public function releaseOrderHolds(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $holds = StockHold::where('order_id', $order->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            if ($holds->isEmpty()) {
                return;
            }

            $this->restoreInventoryAndReleaseHolds($holds);
            DB::afterCommit(fn () => CanteenCache::forgetMenu());
        });
    }

    /**
     * Release all expired holds across the system and restore current_stock in bulk.
     */
    public function releaseExpiredHolds(): int
    {
        $releasedCount = 0;

        DB::transaction(function () use (&$releasedCount) {
            $expiredHolds = StockHold::with('order:id,status')
                ->where('status', 'active')
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->get();

            if ($expiredHolds->isEmpty()) {
                return;
            }

            $releasedCount = $expiredHolds->count();
            $this->restoreInventoryAndReleaseHolds($expiredHolds);

            $pendingOrderIds = $expiredHolds
                ->pluck('order')
                ->filter(fn ($order) => $order !== null && $order->status === 'pending')
                ->pluck('id')
                ->unique()
                ->values()
                ->all();

            if ($pendingOrderIds !== []) {
                Order::whereIn('id', $pendingOrderIds)
                    ->where('status', 'pending')
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
            }

            DB::afterCommit(fn () => CanteenCache::forgetMenu());
        });

        return $releasedCount;
    }

    /**
     * Batch-lock menu items in sorted order, restore stock, and mark holds released.
     *
     * @param  Collection<int, StockHold>  $holds
     */
    protected function restoreInventoryAndReleaseHolds($holds): void
    {
        $restoreByMenuId = [];
        $holdIds = [];

        foreach ($holds as $hold) {
            $holdIds[] = $hold->id;
            $menuId = (int) $hold->menu_item_id;
            $restoreByMenuId[$menuId] = ($restoreByMenuId[$menuId] ?? 0) + (int) $hold->quantity;
        }
        ksort($restoreByMenuId);

        $menuItems = MenuItem::whereIn('id', array_keys($restoreByMenuId))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($restoreByMenuId as $menuId => $qtyToRestore) {
            $menuItem = $menuItems->get($menuId);
            if (! $menuItem) {
                continue;
            }

            $menuItem->current_stock += $qtyToRestore;
            if ($menuItem->current_stock > 0 && $menuItem->is_sold_out) {
                $menuItem->is_sold_out = false;
            }
            $menuItem->save();
        }

        StockHold::whereIn('id', $holdIds)->update([
            'status' => 'released',
            'updated_at' => now(),
        ]);
    }
}
