<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Category;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    /**
     * Get all active menu categories.
     */
    public function categories(): JsonResponse
    {
        $categories = Category::active()
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return ApiResponse::success([
            'categories' => $categories,
        ], 'Categories retrieved successfully');
    }

    /**
     * Get all menu items with category details and real-time stock.
     */
    public function items(Request $request): JsonResponse
    {
        $query = MenuItem::with('category')->orderBy('id');

        if ($request->has('category_id')) {
            $query->where('category_id', $request->query('category_id'));
        }

        if ($request->has('category_slug')) {
            $slug = $request->query('category_slug');
            $query->whereHas('category', function ($q) use ($slug) {
                $q->where('slug', $slug);
            });
        }

        $items = $query->get()->map(function ($item) {
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
                'category_id' => $item->category_id,
                'category' => $item->category?->slug ?? 'meals',
                'category_name' => $item->category?->name ?? 'Meals',
                'name' => $item->name,
                'desc' => $item->description,
                'description' => $item->description,
                'price' => (float) $item->price,
                'image_url' => $item->image_url,
                'emoji' => $emoji,
                'stock' => $item->current_stock,
                'current_stock' => $item->current_stock,
                'daily_stock_limit' => $item->daily_stock_limit,
                'is_sold_out' => (bool) $item->is_sold_out,
                'isSoldOut' => (bool) $item->is_sold_out,
            ];
        });

        // Group by category for consumers that expect grouping
        $grouped = $items->groupBy('category');

        return ApiResponse::success([
            'items' => $items,
            'grouped' => $grouped,
        ], 'Menu items retrieved successfully');
    }
}
