<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentRecord extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'method',
        'amount',
        'reference_number',
        'proof_image_url',
        'status',
        'verified_by_user_id',
        'verified_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'verified_at' => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    public function isGcash(): bool
    {
        return $this->method === 'gcash';
    }
}
