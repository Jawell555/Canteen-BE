<?php

namespace App\Models;

use App\Services\CanteenCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (MenuItem $item) {
            if ($item->is_active === null) {
                $item->is_active = true;
            }
            if ($item->is_active && empty($item->active_date)) {
                $item->active_date = now()->toDateString();
            }
        });

        static::updating(function (MenuItem $item) {
            if ($item->isDirty('is_active')) {
                if ($item->is_active && empty($item->active_date)) {
                    $item->active_date = now()->toDateString();
                } elseif (! $item->is_active) {
                    $item->active_date = null;
                }
            }
        });

        static::saved(fn () => CanteenCache::forgetMenu());
        static::deleted(fn () => CanteenCache::forgetMenu());
    }

    /**
     * Resolve display emoji cleanly when image_url is absent or generic.
     */
    public static function resolveEmoji(?string $name, ?string $imageUrl = null): string
    {
        if ($imageUrl && $imageUrl !== '🍽️') {
            return $imageUrl;
        }

        $lower = strtolower((string) $name);
        if (str_contains($lower, 'adobo')) {
            return '🍛';
        }
        if (str_contains($lower, 'sinigang')) {
            return '🍲';
        }
        if (str_contains($lower, 'inasal')) {
            return '🍗';
        }
        if (str_contains($lower, 'tapsilog')) {
            return '🍳';
        }
        if (str_contains($lower, 'shanghai') || str_contains($lower, 'lumpia')) {
            return '🥢';
        }
        if (str_contains($lower, 'turon')) {
            return '🍌';
        }
        if (str_contains($lower, 'ham') || str_contains($lower, 'cheese')) {
            return '🥪';
        }
        if (str_contains($lower, 'calamansi') || str_contains($lower, 'juice')) {
            return '🧃';
        }
        if (str_contains($lower, 'gulaman') || str_contains($lower, 'sago')) {
            return '🥤';
        }

        return '🍽️';
    }

    protected $fillable = [
        'category_id',
        'name',
        'description',
        'price',
        'image_url',
        'daily_stock_limit',
        'current_stock',
        'is_sold_out',
        'is_active',
        'active_date',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'daily_stock_limit' => 'integer',
            'current_stock' => 'integer',
            'is_sold_out' => 'boolean',
            'is_active' => 'boolean',
            'active_date' => 'date:Y-m-d',
        ];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function stockHolds()
    {
        return $this->hasMany(StockHold::class);
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function favoritedByUsers()
    {
        return $this->belongsToMany(User::class, 'favorites', 'menu_item_id', 'user_id');
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)
            ->where('is_sold_out', false)
            ->where('current_stock', '>', 0);
    }
}
