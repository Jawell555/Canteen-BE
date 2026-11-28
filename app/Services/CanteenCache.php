<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Feedback;
use App\Models\MenuItem;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CanteenCache
{
    public const KEY_CATEGORIES = 'canteena:menu:categories:v1';

    public const KEY_MENU_ITEMS = 'canteena:menu:items:v1';

    public const KEY_FEEDBACK_STATS = 'canteena:feedback:stats:v1';

    /**
     * Use local fast cache store ('file' in prod/local, 'array' in tests)
     * so cache hits never pay remote WAN database round-trip latency.
     */
    public static function store(): Repository
    {
        if (app()->runningUnitTests() || config('cache.default') === 'array') {
            return Cache::store('array');
        }

        return Cache::store('file');
    }

    /**
     * Retrieve cached active categories ordered for catalog display.
     *
     * @return Collection<int, Category>
     */
    public static function getCategories()
    {
        return self::store()->remember(self::KEY_CATEGORIES, 300, function () {
            return Category::active()
                ->orderBy('display_order')
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * Retrieve cached formatted menu catalog items.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function getMenuItems()
    {
        return self::store()->remember(self::KEY_MENU_ITEMS, 120, function () {
            return MenuItem::with('category:id,name,slug')
                ->available()
                ->orderBy('id')
                ->get()
                ->map(function (MenuItem $item) {
                    $emoji = MenuItem::resolveEmoji($item->name, $item->image_url);

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
                        'is_active' => (bool) ($item->is_active ?? true),
                        'is_sold_out' => (bool) $item->is_sold_out,
                        'isSoldOut' => (bool) $item->is_sold_out,
                    ];
                });
        });
    }

    /**
     * Retrieve cached aggregate feedback metrics via single GROUP BY query.
     *
     * @return array{average_rating: float, total_reviews: int, rating_breakdown: array<string, int>}
     */
    public static function getFeedbackStats(): array
    {
        return self::store()->remember(self::KEY_FEEDBACK_STATS, 300, function () {
            $rows = Feedback::query()
                ->selectRaw('rating, COUNT(*) as count')
                ->groupBy('rating')
                ->get();

            $breakdown = ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0];
            $totalReviews = 0;
            $ratingSum = 0;

            foreach ($rows as $row) {
                $r = (int) $row->rating;
                $cnt = (int) $row->count;
                if ($r >= 1 && $r <= 5) {
                    $breakdown[(string) $r] = $cnt;
                    $totalReviews += $cnt;
                    $ratingSum += $r * $cnt;
                }
            }

            return [
                'average_rating' => $totalReviews > 0 ? round($ratingSum / $totalReviews, 2) : 0.0,
                'total_reviews' => $totalReviews,
                'rating_breakdown' => $breakdown,
            ];
        });
    }

    public static function forgetMenu(): void
    {
        $store = self::store();
        $store->forget(self::KEY_CATEGORIES);
        $store->forget(self::KEY_MENU_ITEMS);
    }

    public static function forgetFeedback(): void
    {
        self::store()->forget(self::KEY_FEEDBACK_STATS);
    }
}
