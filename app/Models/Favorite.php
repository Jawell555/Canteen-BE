<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Favorite extends Model
{
    use HasFactory;

    protected $table = 'favorites';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'menu_item_id',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'menu_item_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }
}
