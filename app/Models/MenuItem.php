<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'description',
        'price',
        'image_url',
        'daily_stock_limit',
        'current_stock',
        'is_sold_out',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'daily_stock_limit' => 'integer',
            'current_stock' => 'integer',
            'is_sold_out' => 'boolean',
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

    public function scopeAvailable($query)
    {
        return $query->where('is_sold_out', false)->where('current_stock', '>', 0);
    }
}
