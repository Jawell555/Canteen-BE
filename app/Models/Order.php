<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'daily_queue_number',
        'order_date',
        'user_id',
        'customer_name',
        'customer_phone',
        'guest_token',
        'status',
        'cancellation_reason',
        'subtotal',
        'total_amount',
        'completed_at',
    ];

    protected $appends = [
        'formatted_queue_number',
    ];

    protected function casts(): array
    {
        return [
            'daily_queue_number' => 'integer',
            'order_date' => 'date',
            'subtotal' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function ($order) {
            if (empty($order->uuid)) {
                $order->uuid = (string) Str::uuid();
            }
            if (empty($order->order_date)) {
                $order->order_date = now()->toDateString();
            }
        });
    }

    public function getFormattedQueueNumberAttribute(): string
    {
        return '#'.str_pad((string) $this->daily_queue_number, 3, '0', STR_PAD_LEFT);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function paymentRecord()
    {
        return $this->hasOne(PaymentRecord::class);
    }

    public function payment()
    {
        return $this->hasOne(PaymentRecord::class);
    }

    public function stockHolds()
    {
        return $this->hasMany(StockHold::class);
    }

    public function feedback()
    {
        return $this->hasOne(Feedback::class);
    }
}
