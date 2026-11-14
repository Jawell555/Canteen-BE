<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    use HasFactory;

    protected $table = 'feedback';

    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'user_id', 'rating', 'comments'];

    protected $appends = [
        'order_uuid',
    ];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'user_id' => 'integer',
            'rating' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function getOrderUuidAttribute(): ?string
    {
        return $this->order?->uuid;
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
