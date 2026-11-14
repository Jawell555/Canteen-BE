<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminMenuController extends Controller
{
    /**
     * List all menu items for admin management.
     */
    public function index(): JsonResponse
    {
        $items = MenuItem::with('category')->orderBy('id')->get()->map(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'category_id' => $item->category_id,
                'category' => $item->category?->name ?? 'Meals',
                'category_slug' => $item->category?->slug ?? 'meals',
                'price' => (float) $item->price,
                'daily_stock_limit' => $item->daily_stock_limit,
                'current_stock' => $item->current_stock,
                'is_sold_out' => (bool) $item->is_sold_out,
                'description' => $item->description,
                'desc' => $item->description,
                'image_url' => $item->image_url,
                'created_at' => $item->created_at?->toIso8601String(),
            ];
        });

        return ApiResponse::success([
            'items' => $items,
        ], 'Admin menu items retrieved successfully');
    }

    /**
     * Create a new menu item.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|integer|exists:categories,id',
            'category' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'daily_stock_limit' => 'nullable|integer|min:0',
            'current_stock' => 'nullable|integer|min:0',
            'description' => 'nullable|string|max:1000',
            'desc' => 'nullable|string|max:1000',
            'image_url' => 'nullable|string|max:255',
            'image' => 'nullable|image|max:10240',
        ]);

        $categoryId = $validated['category_id'] ?? null;
        if (! $categoryId && ! empty($validated['category'])) {
            $cat = Category::where('name', $validated['category'])
                ->orWhere('slug', strtolower($validated['category']))
                ->first();
            $categoryId = $cat?->id;
        }

        if (! $categoryId) {
            $firstCat = Category::first();
            $categoryId = $firstCat?->id ?? 1;
        }

        $imageUrl = $validated['image_url'] ?? null;
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('menu', 'public');
            $imageUrl = Storage::url($path);
        }

        $stockLimit = $validated['daily_stock_limit'] ?? 50;
        $currentStock = $validated['current_stock'] ?? $stockLimit;

        $menuItem = MenuItem::create([
            'category_id' => $categoryId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? $validated['desc'] ?? null,
            'price' => $validated['price'],
            'daily_stock_limit' => $stockLimit,
            'current_stock' => $currentStock,
            'is_sold_out' => $currentStock <= 0,
            'image_url' => $imageUrl,
        ]);

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'create_menu_item',
            'entity_type' => 'menu_items',
            'entity_id' => (string) $menuItem->id,
            'details' => ['name' => $menuItem->name, 'price' => $menuItem->price],
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success($menuItem, 'Menu item created successfully', 201);
    }

    /**
     * Update an existing menu item.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $menuItem = MenuItem::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category_id' => 'nullable|integer|exists:categories,id',
            'category' => 'nullable|string',
            'price' => 'sometimes|required|numeric|min:0',
            'daily_stock_limit' => 'nullable|integer|min:0',
            'current_stock' => 'nullable|integer|min:0',
            'is_sold_out' => 'nullable|boolean',
            'description' => 'nullable|string|max:1000',
            'desc' => 'nullable|string|max:1000',
            'image_url' => 'nullable|string|max:255',
            'image' => 'nullable|image|max:10240',
        ]);

        if (! empty($validated['category'])) {
            $cat = Category::where('name', $validated['category'])
                ->orWhere('slug', strtolower($validated['category']))
                ->first();
            if ($cat) {
                $menuItem->category_id = $cat->id;
            }
        } elseif (isset($validated['category_id'])) {
            $menuItem->category_id = $validated['category_id'];
        }

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('menu', 'public');
            $menuItem->image_url = Storage::url($path);
        } elseif (isset($validated['image_url'])) {
            $menuItem->image_url = $validated['image_url'];
        }

        if (isset($validated['name'])) {
            $menuItem->name = $validated['name'];
        }
        if (isset($validated['price'])) {
            $menuItem->price = $validated['price'];
        }
        if (isset($validated['daily_stock_limit'])) {
            $menuItem->daily_stock_limit = $validated['daily_stock_limit'];
        }
        if (isset($validated['current_stock'])) {
            $menuItem->current_stock = $validated['current_stock'];
        }
        if (isset($validated['is_sold_out'])) {
            $menuItem->is_sold_out = (bool) $validated['is_sold_out'];
        }
        if (isset($validated['description']) || isset($validated['desc'])) {
            $menuItem->description = $validated['description'] ?? $validated['desc'];
        }

        $menuItem->save();

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'update_menu_item',
            'entity_type' => 'menu_items',
            'entity_id' => (string) $menuItem->id,
            'details' => $validated,
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success($menuItem, 'Menu item updated successfully');
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
}
