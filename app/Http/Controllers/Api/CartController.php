<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CartItem;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    /**
     * Retrieve the authenticated customer's persisted cart with live menu data.
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->formatCartResponse($request->user()),
            'Cart retrieved successfully'
        );
    }

    /**
     * Add an item (or increment existing item with matching notes) atomically in the cart.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'menu_item_id' => 'required|integer|exists:menu_items,id',
            'quantity' => 'required|integer|min:1|max:500',
            'custom_notes' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $notes = trim((string) ($validated['custom_notes'] ?? ''));
        $addQty = (int) $validated['quantity'];

        $error = DB::transaction(function () use ($user, $validated, $notes, $addQty) {
            $menuItem = MenuItem::findOrFail($validated['menu_item_id']);
            if ($menuItem->is_sold_out || $menuItem->current_stock <= 0) {
                return ApiResponse::error("'{$menuItem->name}' is currently sold out.", 422);
            }

            $userItemRows = CartItem::where('user_id', $user->id)
                ->where('menu_item_id', $menuItem->id)
                ->lockForUpdate()
                ->get();

            $existing = $userItemRows->first(
                fn (CartItem $item) => trim((string) $item->custom_notes) === $notes
            );

            $totalInCartForMenu = (int) $userItemRows->sum('quantity');
            $projectedTotal = $totalInCartForMenu + $addQty;

            if ($projectedTotal > $menuItem->current_stock) {
                $remaining = max(0, (int) $menuItem->current_stock - $totalInCartForMenu);

                return ApiResponse::error(
                    $remaining > 0
                        ? "Only {$remaining} more available in stock for '{$menuItem->name}'."
                        : "Only {$menuItem->current_stock} remaining in stock for '{$menuItem->name}'.",
                    422
                );
            }

            if ($existing) {
                $existing->quantity += $addQty;
                $existing->save();
            } else {
                CartItem::create([
                    'user_id' => $user->id,
                    'menu_item_id' => $menuItem->id,
                    'quantity' => $addQty,
                    'custom_notes' => $notes !== '' ? $notes : null,
                ]);
            }

            return null;
        });

        if ($error !== null) {
            return $error;
        }

        return ApiResponse::success(
            $this->formatCartResponse($user),
            'Item added to cart'
        );
    }

    /**
     * Update quantity or custom notes for a specific cart item atomically.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => 'sometimes|integer|min:0|max:500',
            'custom_notes' => 'nullable|string|max:500',
        ]);

        $user = $request->user();

        $result = DB::transaction(function () use ($user, $id, $validated) {
            $cartItem = CartItem::with('menuItem')
                ->where('user_id', $user->id)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $cartItem) {
                return ['error' => ApiResponse::error('Cart item not found.', 404)];
            }

            if (array_key_exists('quantity', $validated) && (int) $validated['quantity'] <= 0) {
                $cartItem->delete();

                return ['message' => 'Item removed from cart'];
            }

            if (array_key_exists('quantity', $validated)) {
                $menuItem = $cartItem->menuItem;
                if (! $menuItem || $menuItem->is_sold_out || $menuItem->current_stock <= 0) {
                    return ['error' => ApiResponse::error('This item is currently sold out.', 422)];
                }

                $requestedQty = (int) $validated['quantity'];
                $otherNotesQty = (int) CartItem::where('user_id', $user->id)
                    ->where('menu_item_id', $cartItem->menu_item_id)
                    ->where('id', '!=', $cartItem->id)
                    ->sum('quantity');

                if (($requestedQty + $otherNotesQty) > $menuItem->current_stock) {
                    $maxForLine = max(0, (int) $menuItem->current_stock - $otherNotesQty);

                    return ['error' => ApiResponse::error(
                        "Only {$maxForLine} available in stock for '{$menuItem->name}'.",
                        422
                    )];
                }

                $cartItem->quantity = $requestedQty;
            }

            if (array_key_exists('custom_notes', $validated)) {
                $notes = trim((string) ($validated['custom_notes'] ?? ''));
                $cartItem->custom_notes = $notes !== '' ? $notes : null;
            }

            $cartItem->save();

            return ['message' => 'Cart item updated'];
        });

        if (isset($result['error'])) {
            return $result['error'];
        }

        return ApiResponse::success(
            $this->formatCartResponse($user),
            $result['message']
        );
    }

    /**
     * Remove a single item from the authenticated customer's cart.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $deleted = CartItem::where('user_id', $user->id)
            ->where('id', $id)
            ->delete();

        if ($deleted === 0) {
            return ApiResponse::error('Cart item not found.', 404);
        }

        return ApiResponse::success(
            $this->formatCartResponse($user),
            'Item removed from cart'
        );
    }

    /**
     * Clear all items from the authenticated customer's cart.
     */
    public function clear(Request $request): JsonResponse
    {
        $user = $request->user();
        CartItem::where('user_id', $user->id)->delete();

        return ApiResponse::success(
            [
                'items' => [],
                'item_count' => 0,
                'subtotal' => 0.0,
            ],
            'Cart cleared'
        );
    }

    /**
     * Merge guest cart items into the authenticated customer's persisted cart on login.
     */
    public function merge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.menu_item_id' => 'required|integer|exists:menu_items,id',
            'items.*.quantity' => 'required|integer|min:1|max:500',
            'items.*.custom_notes' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $menuIds = collect($validated['items'])->pluck('menu_item_id')->unique()->values();
        $menuItemsMap = MenuItem::whereIn('id', $menuIds)->get()->keyBy('id');

        DB::transaction(function () use ($user, $validated, $menuItemsMap) {
            $existingRows = CartItem::where('user_id', $user->id)
                ->whereIn('menu_item_id', $menuItemsMap->keys())
                ->lockForUpdate()
                ->get();

            foreach ($validated['items'] as $guestItem) {
                $menuItem = $menuItemsMap->get($guestItem['menu_item_id']);
                if (! $menuItem || $menuItem->is_sold_out || $menuItem->current_stock <= 0) {
                    continue;
                }

                $notes = trim((string) ($guestItem['custom_notes'] ?? ''));
                $itemGroup = $existingRows->where('menu_item_id', $menuItem->id);
                $existing = $itemGroup->first(
                    fn (CartItem $item) => trim((string) $item->custom_notes) === $notes
                );

                $totalForMenu = (int) $itemGroup->sum('quantity');
                $remainingAllowance = max(0, (int) $menuItem->current_stock - $totalForMenu);
                $addQty = min((int) $guestItem['quantity'], $remainingAllowance);

                if ($addQty <= 0) {
                    continue;
                }

                if ($existing) {
                    $existing->quantity += $addQty;
                    $existing->save();
                } else {
                    $created = CartItem::create([
                        'user_id' => $user->id,
                        'menu_item_id' => $menuItem->id,
                        'quantity' => $addQty,
                        'custom_notes' => $notes !== '' ? $notes : null,
                    ]);
                    $existingRows->push($created);
                }
            }
        });

        return ApiResponse::success(
            $this->formatCartResponse($user),
            'Guest cart merged successfully'
        );
    }

    /**
     * Format the user's cart items with authoritative pricing, exact cent totals, and stock.
     *
     * @return array{items: array<int, array<string, mixed>>, item_count: int, subtotal: float}
     */
    protected function formatCartResponse(User $user): array
    {
        $cartItems = CartItem::with([
            'menuItem:id,category_id,name,price,image_url,current_stock,is_sold_out',
            'menuItem.category:id,name',
        ])
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->filter(fn (CartItem $ci) => $ci->menuItem !== null);

        $itemCount = 0;
        $subtotalCents = 0;

        $formatted = $cartItems->map(function (CartItem $ci) use (&$itemCount, &$subtotalCents) {
            $menuItem = $ci->menuItem;
            $price = (float) $menuItem->price;
            $qty = (int) $ci->quantity;

            $itemCount += $qty;
            $subtotalCents += (int) round($price * 100) * $qty;

            return [
                'id' => $ci->id,
                'cart_item_id' => $ci->id,
                'menu_item_id' => $ci->menu_item_id,
                'name' => $menuItem->name,
                'price' => $price,
                'quantity' => $qty,
                'custom_notes' => $ci->custom_notes ?? '',
                'image_url' => $menuItem->image_url ?? '',
                'emoji' => MenuItem::resolveEmoji($menuItem->name, $menuItem->image_url),
                'category_name' => $menuItem->category?->name ?? '',
                'current_stock' => (int) $menuItem->current_stock,
                'is_sold_out' => (bool) $menuItem->is_sold_out,
            ];
        })->values();

        return [
            'items' => $formatted->all(),
            'item_count' => $itemCount,
            'subtotal' => round($subtotalCents / 100, 2),
        ];
    }
}
