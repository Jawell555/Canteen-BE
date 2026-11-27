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
     * Add an item (or increment existing item with matching notes) in the cart.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'menu_item_id' => 'required|integer|exists:menu_items,id',
            'quantity' => 'required|integer|min:1|max:50',
            'custom_notes' => 'nullable|string|max:500',
        ]);

        $menuItem = MenuItem::findOrFail($validated['menu_item_id']);
        if ($menuItem->is_sold_out || $menuItem->current_stock <= 0) {
            return ApiResponse::error("'{$menuItem->name}' is currently sold out.", 422);
        }

        $user = $request->user();
        $notes = trim((string) ($validated['custom_notes'] ?? ''));
        $existing = $this->findMatchingItem($user, $menuItem->id, $notes);
        $newQuantity = ($existing ? $existing->quantity : 0) + (int) $validated['quantity'];

        if ($newQuantity > $menuItem->current_stock) {
            return ApiResponse::error(
                "Only {$menuItem->current_stock} remaining in stock for '{$menuItem->name}'.",
                422
            );
        }

        if ($existing) {
            $existing->quantity = $newQuantity;
            $existing->save();
        } else {
            CartItem::create([
                'user_id' => $user->id,
                'menu_item_id' => $menuItem->id,
                'quantity' => $newQuantity,
                'custom_notes' => $notes !== '' ? $notes : null,
            ]);
        }

        return ApiResponse::success(
            $this->formatCartResponse($user),
            'Item added to cart'
        );
    }

    /**
     * Update quantity or custom notes for a specific cart item.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => 'sometimes|integer|min:0|max:50',
            'custom_notes' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $cartItem = CartItem::with('menuItem')
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (! $cartItem) {
            return ApiResponse::error('Cart item not found.', 404);
        }

        if (array_key_exists('quantity', $validated) && (int) $validated['quantity'] <= 0) {
            $cartItem->delete();

            return ApiResponse::success(
                $this->formatCartResponse($user),
                'Item removed from cart'
            );
        }

        if (array_key_exists('quantity', $validated)) {
            $menuItem = $cartItem->menuItem;
            if (! $menuItem || $menuItem->is_sold_out || $menuItem->current_stock <= 0) {
                return ApiResponse::error('This item is currently sold out.', 422);
            }
            $requestedQty = (int) $validated['quantity'];
            if ($requestedQty > $menuItem->current_stock) {
                return ApiResponse::error(
                    "Only {$menuItem->current_stock} remaining in stock for '{$menuItem->name}'.",
                    422
                );
            }
            $cartItem->quantity = $requestedQty;
        }

        if (array_key_exists('custom_notes', $validated)) {
            $notes = trim((string) ($validated['custom_notes'] ?? ''));
            $cartItem->custom_notes = $notes !== '' ? $notes : null;
        }

        $cartItem->save();

        return ApiResponse::success(
            $this->formatCartResponse($user),
            'Cart item updated'
        );
    }

    /**
     * Remove a single item from the authenticated customer's cart.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $cartItem = CartItem::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (! $cartItem) {
            return ApiResponse::error('Cart item not found.', 404);
        }

        $cartItem->delete();

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
            $this->formatCartResponse($user),
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
            'items.*.quantity' => 'required|integer|min:1|max:50',
            'items.*.custom_notes' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $menuIds = collect($validated['items'])->pluck('menu_item_id')->unique()->values();
        $menuItemsMap = MenuItem::whereIn('id', $menuIds)->get()->keyBy('id');

        DB::transaction(function () use ($user, $validated, $menuItemsMap) {
            foreach ($validated['items'] as $guestItem) {
                $menuItem = $menuItemsMap->get($guestItem['menu_item_id']);
                if (! $menuItem || $menuItem->is_sold_out || $menuItem->current_stock <= 0) {
                    continue;
                }

                $notes = trim((string) ($guestItem['custom_notes'] ?? ''));
                $existing = $this->findMatchingItem($user, $menuItem->id, $notes);
                $desiredQty = ($existing ? $existing->quantity : 0) + (int) $guestItem['quantity'];
                $cappedQty = min($desiredQty, (int) $menuItem->current_stock);

                if ($cappedQty <= 0) {
                    continue;
                }

                if ($existing) {
                    $existing->quantity = $cappedQty;
                    $existing->save();
                } else {
                    CartItem::create([
                        'user_id' => $user->id,
                        'menu_item_id' => $menuItem->id,
                        'quantity' => $cappedQty,
                        'custom_notes' => $notes !== '' ? $notes : null,
                    ]);
                }
            }
        });

        return ApiResponse::success(
            $this->formatCartResponse($user),
            'Guest cart merged successfully'
        );
    }

    /**
     * Find an existing cart item matching menu_item_id and normalized custom_notes.
     */
    protected function findMatchingItem(User $user, int $menuItemId, string $normalizedNotes): ?CartItem
    {
        return CartItem::where('user_id', $user->id)
            ->where('menu_item_id', $menuItemId)
            ->get()
            ->first(fn (CartItem $item) => trim((string) $item->custom_notes) === $normalizedNotes);
    }

    /**
     * Format the user's cart items with authoritative pricing and stock.
     *
     * @return array{items: array<int, array<string, mixed>>, item_count: int, subtotal: float}
     */
    protected function formatCartResponse(User $user): array
    {
        $cartItems = CartItem::with('menuItem.category')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->filter(fn (CartItem $ci) => $ci->menuItem !== null);

        $formatted = $cartItems->map(function (CartItem $ci) {
            $menuItem = $ci->menuItem;

            return [
                'id' => $ci->id,
                'cart_item_id' => $ci->id,
                'menu_item_id' => $ci->menu_item_id,
                'name' => $menuItem->name,
                'price' => (float) $menuItem->price,
                'quantity' => (int) $ci->quantity,
                'custom_notes' => $ci->custom_notes ?? '',
                'image_url' => $menuItem->image_url ?? '',
                'category_name' => $menuItem->category?->name ?? '',
                'current_stock' => (int) $menuItem->current_stock,
                'is_sold_out' => (bool) $menuItem->is_sold_out,
            ];
        })->values();

        $itemCount = (int) $formatted->sum('quantity');
        $subtotal = round((float) $formatted->sum(fn ($item) => $item['price'] * $item['quantity']), 2);

        return [
            'items' => $formatted->all(),
            'item_count' => $itemCount,
            'subtotal' => $subtotal,
        ];
    }
}
