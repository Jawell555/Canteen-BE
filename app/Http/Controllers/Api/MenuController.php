<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\CanteenCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    /**
     * Get all active menu categories.
     */
    public function categories(): JsonResponse
    {
        $categories = CanteenCache::getCategories();

        return ApiResponse::success([
            'categories' => $categories,
        ], 'Categories retrieved successfully');
    }

    /**
     * Get all menu items with category details and real-time stock.
     */
    public function items(Request $request): JsonResponse
    {
        $items = CanteenCache::getMenuItems();

        if ($request->filled('category_id')) {
            $categoryId = (int) $request->query('category_id');
            $items = $items->where('category_id', $categoryId)->values();
        }

        if ($request->filled('category_slug')) {
            $slug = (string) $request->query('category_slug');
            $items = $items->where('category', $slug)->values();
        }

        // Group by category for consumers that expect grouping
        $grouped = $items->groupBy('category');

        return ApiResponse::success([
            'items' => $items,
            'grouped' => $grouped,
        ], 'Menu items retrieved successfully');
    }
}
