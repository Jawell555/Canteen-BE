<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'email',
        'phone_number',
        'password',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Automatically assign a UUID when creating a new user.
     */
    protected static function booted(): void
    {
        static::creating(function ($user) {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Check if user is staff or admin.
     */
    public function isStaff(): bool
    {
        return in_array($this->role, ['staff', 'admin'], true);
    }

    /**
     * Check if user is admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Relationship with AuditLogs.
     */
    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Relationship with Orders.
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Relationship with Feedbacks.
     */
    public function feedbacks()
    {
        return $this->hasMany(Feedback::class);
    }

    /**
     * Relationship with Favorites.
     */
    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * Belongs to many favorite MenuItems.
     */
    public function favoriteMenuItems()
    {
        return $this->belongsToMany(MenuItem::class, 'favorites', 'user_id', 'menu_item_id');
    }
}
