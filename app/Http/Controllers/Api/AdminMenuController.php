<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminMenuController extends Controller
{
    /**
     * List all menu items for admin management with live today's ordered counts.
     */
    public function index(): JsonResponse
    {
        $today = Carbon::today()->toDateString();

        $orderedTodayMap = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereDate('orders.order_date', $today)
            ->where('orders.status', '!=', 'cancelled')
            ->selectRaw('order_items.menu_item_id, COALESCE(SUM(order_items.quantity), 0) as ordered_qty')
            ->groupBy('order_items.menu_item_id')
            ->pluck('ordered_qty', 'order_items.menu_item_id');

        $items = MenuItem::with('category:id,name,slug')
            ->orderBy('id')
            ->get()
            ->map(function (MenuItem $item) use ($orderedTodayMap) {
                return $this->formatAdminItem($item, (int) ($orderedTodayMap->get($item->id) ?? 0));
            });

        return ApiResponse::success([
            'items' => $items,
            'today_date' => $today,
        ], 'Admin menu items retrieved successfully');
    }

    /**
     * Create a new menu item with optional photo upload.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|integer|exists:categories,id',
            'category' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'quantity' => 'nullable|integer|min:0',
            'daily_stock_limit' => 'nullable|integer|min:0',
            'current_stock' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string|max:1000',
            'desc' => 'nullable|string|max:1000',
            'image_url' => 'nullable|string|max:500',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp,gif|max:5120',
        ]);

        $categoryId = $this->resolveCategoryId($validated['category_id'] ?? null, $validated['category'] ?? null);

        $imageUrl = $validated['image_url'] ?? null;
        if ($request->hasFile('image')) {
            $imageUrl = $this->storeMenuImage($request);
        }

        $quantity = (int) ($validated['quantity'] ?? $validated['current_stock'] ?? $validated['daily_stock_limit'] ?? 50);
        $stockLimit = (int) ($validated['daily_stock_limit'] ?? $quantity);

        $isActive = array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true;

        $menuItem = MenuItem::create([
            'category_id' => $categoryId,
            'name' => trim($validated['name']),
            'description' => $validated['description'] ?? $validated['desc'] ?? null,
            'price' => $validated['price'],
            'daily_stock_limit' => $stockLimit,
            'current_stock' => $quantity,
            'is_sold_out' => $quantity <= 0,
            'is_active' => $isActive,
            'active_date' => $isActive ? Carbon::today()->toDateString() : null,
            'image_url' => $imageUrl,
        ]);

        $menuItem->load('category:id,name,slug');

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'create_menu_item',
            'entity_type' => 'menu_items',
            'entity_id' => (string) $menuItem->id,
            'details' => ['name' => $menuItem->name, 'price' => $menuItem->price, 'quantity' => $quantity],
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success(
            $this->formatAdminItem($menuItem, 0),
            'Menu item created successfully',
            201
        );
    }

    /**
     * Update an existing menu item, its quantity, status, or uploaded picture.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $menuItem = MenuItem::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category_id' => 'nullable|integer|exists:categories,id',
            'category' => 'nullable|string',
            'price' => 'sometimes|required|numeric|min:0',
            'quantity' => 'nullable|integer|min:0',
            'daily_stock_limit' => 'nullable|integer|min:0',
            'current_stock' => 'nullable|integer|min:0',
            'is_sold_out' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string|max:1000',
            'desc' => 'nullable|string|max:1000',
            'image_url' => 'nullable|string|max:500',
            'remove_image' => 'nullable|boolean',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp,gif|max:5120',
        ]);

        if (! empty($validated['category']) || isset($validated['category_id'])) {
            $menuItem->category_id = $this->resolveCategoryId($validated['category_id'] ?? null, $validated['category'] ?? null);
        }

        if ($request->hasFile('image')) {
            $menuItem->image_url = $this->storeMenuImage($request);
        } elseif (! empty($validated['remove_image'])) {
            $menuItem->image_url = null;
        } elseif (array_key_exists('image_url', $validated)) {
            $menuItem->image_url = $validated['image_url'];
        }

        if (isset($validated['name'])) {
            $menuItem->name = trim($validated['name']);
        }
        if (isset($validated['price'])) {
            $menuItem->price = $validated['price'];
        }

        if (array_key_exists('quantity', $validated) && $validated['quantity'] !== null) {
            $newQty = (int) $validated['quantity'];
            $menuItem->current_stock = $newQty;
            $menuItem->daily_stock_limit = max($newQty, (int) $menuItem->daily_stock_limit);
            if (! array_key_exists('is_sold_out', $validated)) {
                $menuItem->is_sold_out = $newQty <= 0;
            }
        } else {
            if (array_key_exists('daily_stock_limit', $validated) && $validated['daily_stock_limit'] !== null) {
                $menuItem->daily_stock_limit = (int) $validated['daily_stock_limit'];
            }
            if (array_key_exists('current_stock', $validated) && $validated['current_stock'] !== null) {
                $menuItem->current_stock = (int) $validated['current_stock'];
            }
        }

        if (array_key_exists('is_sold_out', $validated) && $validated['is_sold_out'] !== null) {
            $menuItem->is_sold_out = (bool) $validated['is_sold_out'];
        }
        if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
            $menuItem->is_active = (bool) $validated['is_active'];
            $menuItem->active_date = $menuItem->is_active ? Carbon::today()->toDateString() : null;
        }
        if (array_key_exists('description', $validated) || array_key_exists('desc', $validated)) {
            $menuItem->description = $validated['description'] ?? $validated['desc'];
        }

        $menuItem->save();
        $menuItem->load('category:id,name,slug');

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'update_menu_item',
            'entity_type' => 'menu_items',
            'entity_id' => (string) $menuItem->id,
            'details' => collect($validated)->except(['image'])->all(),
            'ip_address' => $request->ip(),
        ]);

        $orderedToday = (int) OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereDate('orders.order_date', Carbon::today()->toDateString())
            ->where('orders.status', '!=', 'cancelled')
            ->where('order_items.menu_item_id', $menuItem->id)
            ->sum('order_items.quantity');

        return ApiResponse::success(
            $this->formatAdminItem($menuItem, $orderedToday),
            'Menu item updated successfully'
        );
    }

    /**
     * Activate or deactivate a meal on today's customer menu without deleting it.
     */
    public function toggleActive(Request $request, int $id): JsonResponse
    {
        $menuItem = MenuItem::findOrFail($id);

        if ($request->has('is_active')) {
            $menuItem->is_active = (bool) $request->input('is_active');
        } else {
            $menuItem->is_active = ! ($menuItem->is_active ?? true);
        }

        $menuItem->active_date = $menuItem->is_active ? Carbon::today()->toDateString() : null;
        $menuItem->save();

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'toggle_menu_active',
            'entity_type' => 'menu_items',
            'entity_id' => (string) $menuItem->id,
            'details' => [
                'item_name' => $menuItem->name,
                'is_active' => $menuItem->is_active,
                'active_date' => $menuItem->active_date?->toDateString(),
            ],
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success([
            'id' => $menuItem->id,
            'is_active' => (bool) $menuItem->is_active,
            'active_date' => $menuItem->active_date?->toDateString(),
        ], $menuItem->is_active ? 'Meal activated for today\'s menu' : 'Meal deactivated from today\'s menu');
    }

    /**
     * Delete a menu item.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $menuItem = MenuItem::findOrFail($id);
        $itemName = $menuItem->name;
        $menuItem->delete();

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'delete_menu_item',
            'entity_type' => 'menu_items',
            'entity_id' => (string) $id,
            'details' => ['name' => $itemName],
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success(null, 'Menu item deleted successfully');
    }

    protected function resolveCategoryId(?int $categoryId, ?string $categoryName): int
    {
        if ($categoryId) {
            return $categoryId;
        }

        if ($categoryName) {
            $cat = Category::where('name', $categoryName)
                ->orWhere('slug', strtolower($categoryName))
                ->first();
            if ($cat) {
                return $cat->id;
            }
        }

        return Category::first()?->id ?? 1;
    }

    protected function storeMenuImage(Request $request): string
    {
        $path = $request->file('image')->store('menu', 'public');

        return $request->getSchemeAndHttpHost().'/api/v1/media/menu/'.basename($path);
    }

    /**
     * Format menu item consistently for the Admin Management Portal.
     *
     * @return array<string, mixed>
     */
    protected function formatAdminItem(MenuItem $item, int $orderedToday): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'category_id' => $item->category_id,
            'category' => $item->category?->name ?? 'Meals',
            'category_slug' => $item->category?->slug ?? 'meals',
            'price' => number_format((float) $item->price, 2, '.', ''),
            'daily_stock_limit' => (int) $item->daily_stock_limit,
            'current_stock' => (int) $item->current_stock,
            'quantity' => (int) $item->current_stock,
            'ordered_count' => $orderedToday,
            'is_active' => (bool) ($item->is_active ?? true),
            'active_date' => $item->active_date?->toDateString(),
            'is_sold_out' => (bool) $item->is_sold_out,
            'description' => $item->description,
            'desc' => $item->description,
            'image_url' => $item->image_url,
            'emoji' => MenuItem::resolveEmoji($item->name, $item->image_url),
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }
}
