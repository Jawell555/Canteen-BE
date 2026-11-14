<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class QueueNumberService
{
    /**
     * Generate next sequential queue number for the given date.
     *
     * @return array{number: int, formatted: string}
     */
    public function getNextQueueNumber(?string $orderDate = null): array
    {
        $date = $orderDate ?: Carbon::today()->toDateString();

        return DB::transaction(function () use ($date) {
            // Using whereDate ensures compatibility across PostgreSQL and SQLite
            // when timestamps or date strings are formatted differently.
            $highestOrder = Order::whereDate('order_date', $date)
                ->orderByDesc('daily_queue_number')
                ->lockForUpdate()
                ->first();

            $maxNumber = $highestOrder ? (int) $highestOrder->daily_queue_number : 0;
            $nextNumber = ($maxNumber >= 999) ? 1 : $maxNumber + 1;
            $formatted = '#'.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);

            return [
                'number' => $nextNumber,
                'formatted' => $formatted,
            ];
        });
    }
}
