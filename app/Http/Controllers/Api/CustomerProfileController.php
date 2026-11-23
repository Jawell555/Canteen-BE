<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Favorite;
use App\Models\MenuItem;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerProfileController extends Controller
{
    /**
     * Retrieve authenticated customer's paginated order history with item details.
     */
    public function orders(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = $request->integer('per_page', 10);

        $paginator = Order::with(['orderItems.menuItem', 'paymentRecord', 'feedback'])
            ->where('user_id', $user->id)
            ->latest()
            ->paginate($perPage);

        $orders = $paginator->getCollection()->map(function ($order) use ($user) {
            $paymentMethod = 'Cash';
            if ($order->paymentRecord) {
                $paymentMethod = $order->paymentRecord->method === 'gcash' ? 'GCash' : 'Cash at Counter';
            }

            return [
                'id' => $order->id,
                'order_uuid' => $order->uuid,
                'uuid' => $order->uuid,
                'customer_name' => $order->customer_name ?: ($user->name ?? 'Customer'),
                'queue_number' => $order->formatted_queue_number,
                'daily_queue_number' => $order->daily_queue_number,
                'date' => $order->created_at ? $order->created_at->format('M d, Y • h:i A') : '',
                'created_at' => $order->created_at ? $order->created_at->toISOString() : null,
                'status' => $order->status,
                'total' => (float) $order->total_amount,
                'total_amount' => (float) $order->total_amount,
                'payment_method' => $paymentMethod,
                'payment' => $order->paymentRecord,
                'has_feedback' => $order->feedback !== null,
                'items' => $order->orderItems->map(function ($oi) {
                    return [
                        'id' => $oi->menu_item_id,
                        'name' => $oi->item_name ?: $oi->menuItem?->name ?: 'Item',
                        'price' => (float) $oi->unit_price,
                        'unit_price' => (float) $oi->unit_price,
                        'quantity' => (int) $oi->quantity,
                        'subtotal' => (float) $oi->subtotal,
                        'custom_notes' => $oi->custom_notes ?: '',
                        'image_url' => $oi->menuItem?->image_url,
                    ];
                })->values(),
                'order_items' => $order->orderItems,
            ];
        });

        return ApiResponse::success([
            'orders' => $orders,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Customer order history retrieved successfully');
    }

    /**
     * Retrieve list of starred/favorited menu items for authenticated customer.
     */
    public function favorites(Request $request): JsonResponse
    {
        $user = $request->user();

        $favorites = Favorite::with('menuItem.category')
            ->where('user_id', $user->id)
            ->latest('id')
            ->get()
            ->map(function ($fav) {
                $item = $fav->menuItem;
                if (! $item) {
                    return null;
                }

                $emoji = $item->image_url;
                if (! $emoji || $emoji === '🍽️') {
                    $name = strtolower($item->name);
                    if (str_contains($name, 'adobo')) {
                        $emoji = '🍛';
                    } elseif (str_contains($name, 'sinigang')) {
                        $emoji = '🍲';
                    } elseif (str_contains($name, 'inasal')) {
                        $emoji = '🍗';
                    } elseif (str_contains($name, 'tapsilog')) {
                        $emoji = '🍳';
                    } elseif (str_contains($name, 'shanghai') || str_contains($name, 'lumpia')) {
                        $emoji = '🥢';
                    } elseif (str_contains($name, 'turon')) {
                        $emoji = '🍌';
                    } elseif (str_contains($name, 'ham') || str_contains($name, 'cheese')) {
                        $emoji = '🥪';
                    } elseif (str_contains($name, 'calamansi') || str_contains($name, 'juice')) {
                        $emoji = '🧃';
                    } elseif (str_contains($name, 'gulaman') || str_contains($name, 'sago')) {
                        $emoji = '🥤';
                    } else {
                        $emoji = '🍽️';
                    }
                }

                return [
                    'id' => $item->id,
                    'favorite_id' => $fav->id,
                    'menu_item_id' => $item->id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'desc' => $item->description,
                    'price' => (float) $item->price,
                    'image_url' => $item->image_url,
                    'emoji' => $emoji,
                    'category_id' => $item->category_id,
                    'category' => $item->category?->slug ?? $item->category?->name ?? 'meals',
                    'category_name' => $item->category?->name ?? 'Meals',
                    'is_sold_out' => (bool) $item->is_sold_out,
                    'isSoldOut' => (bool) $item->is_sold_out,
                    'stock' => (int) $item->current_stock,
                    'current_stock' => (int) $item->current_stock,
                    'favorited_at' => $fav->created_at ? $fav->created_at->toISOString() : null,
                ];
            })
            ->filter()
            ->values();

        return ApiResponse::success([
            'favorites' => $favorites,
            'items' => $favorites,
            'favorite_ids' => $favorites->pluck('id')->values(),
        ], 'Favorite items retrieved successfully');
    }

    /**
     * Atomic attach/detach logic for starred menu items.
     */
    public function toggleFavorite(Request $request): JsonResponse
    {
        $request->validate([
            'menu_item_id' => 'required_without:item_id|integer',
            'item_id' => 'sometimes|integer',
        ]);

        $menuItemId = $request->input('menu_item_id') ?? $request->input('item_id');

        $menuItem = MenuItem::find($menuItemId);
        if (! $menuItem) {
            return ApiResponse::error('Menu item not found.', 404);
        }

        $user = $request->user();

        $existing = Favorite::where('user_id', $user->id)
            ->where('menu_item_id', $menuItem->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $isFavorited = false;
            $message = 'Item removed from favorites';
        } else {
            Favorite::create([
                'user_id' => $user->id,
                'menu_item_id' => $menuItem->id,
            ]);
            $isFavorited = true;
            $message = 'Item added to favorites';
        }

        return ApiResponse::success([
            'menu_item_id' => (int) $menuItem->id,
            'is_favorited' => $isFavorited,
            'action' => $isFavorited ? 'added' : 'removed',
        ], $message);
    }
}
