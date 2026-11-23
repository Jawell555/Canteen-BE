<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CanteenDatabaseSeeder extends Seeder
{
    /**
     * Seed realistic Filipino canteen items and default roles.
     */
    public function run(): void
    {
        // 1. Seed Accounts
        User::firstOrCreate(
            ['email' => 'admin@canteen.local'],
            [
                'name' => 'Canteen Administrator',
                'phone_number' => '09170000001',
                'password' => Hash::make('admin123'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['email' => 'staff@canteen.local'],
            [
                'name' => 'Kitchen Staff Lead',
                'phone_number' => '09170000002',
                'password' => Hash::make('staff123'),
                'role' => 'staff',
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['email' => 'customer@canteen.local'],
            [
                'name' => 'Juan Dela Cruz',
                'phone_number' => '09170000003',
                'password' => Hash::make('customer123'),
                'role' => 'customer',
                'is_active' => true,
            ]
        );

        // 2. Seed Categories
        $meals = Category::firstOrCreate(
            ['slug' => 'meals'],
            ['name' => 'Meals', 'display_order' => 1, 'is_active' => true]
        );

        $snacks = Category::firstOrCreate(
            ['slug' => 'snacks'],
            ['name' => 'Snacks', 'display_order' => 2, 'is_active' => true]
        );

        $drinks = Category::firstOrCreate(
            ['slug' => 'drinks'],
            ['name' => 'Drinks', 'display_order' => 3, 'is_active' => true]
        );

        $others = Category::firstOrCreate(
            ['slug' => 'others'],
            ['name' => 'Others', 'display_order' => 4, 'is_active' => true]
        );

        // 3. Seed Menu Items
        $items = [
            // Meals
            [
                'category_id' => $meals->id,
                'name' => 'Chicken Adobo with Rice',
                'description' => 'Classic savory chicken braised in vinegar, soy sauce, garlic, and bay leaves served with steamed white rice.',
                'price' => 85.00,
                'daily_stock_limit' => 50,
                'current_stock' => 50,
                'is_sold_out' => false,
            ],
            [
                'category_id' => $meals->id,
                'name' => 'Pork Sinigang with Rice',
                'description' => 'Tamarind-soured broth with tender pork ribs, water spinach (kangkong), radish, and chili pepper.',
                'price' => 95.00,
                'daily_stock_limit' => 50,
                'current_stock' => 50,
                'is_sold_out' => false,
            ],
            [
                'category_id' => $meals->id,
                'name' => 'Chicken Inasal with Rice',
                'description' => 'Bacolod-style grilled marinated quarter chicken basted with annatto oil, calamansi, and garlic.',
                'price' => 90.00,
                'daily_stock_limit' => 50,
                'current_stock' => 50,
                'is_sold_out' => false,
            ],
            [
                'category_id' => $meals->id,
                'name' => 'Tapsilog',
                'description' => 'Cured beef tapa strips served with savory garlic fried rice (sinangag) and sunny-side-up fried egg.',
                'price' => 80.00,
                'daily_stock_limit' => 50,
                'current_stock' => 50,
                'is_sold_out' => false,
            ],

            // Snacks
            [
                'category_id' => $snacks->id,
                'name' => 'Lumpiang Shanghai (6 pcs)',
                'description' => 'Crisp golden fried spring rolls filled with seasoned minced pork and carrots, served with sweet chili sauce.',
                'price' => 50.00,
                'daily_stock_limit' => 50,
                'current_stock' => 50,
                'is_sold_out' => false,
            ],
            [
                'category_id' => $snacks->id,
                'name' => 'Crispy Turon (2 pcs)',
                'description' => 'Sweet saba banana with jackfruit strips wrapped in lumpia pastry, glazed with caramelized brown sugar.',
                'price' => 25.00,
                'daily_stock_limit' => 50,
                'current_stock' => 50,
                'is_sold_out' => false,
            ],
            [
                'category_id' => $snacks->id,
                'name' => 'Toasted Ham & Cheese',
                'description' => 'Golden crisp sandwich bread filled with smoked ham and melted cheddar cheese slices.',
                'price' => 45.00,
                'daily_stock_limit' => 50,
                'current_stock' => 50,
                'is_sold_out' => false,
            ],

            // Drinks
            [
                'category_id' => $drinks->id,
                'name' => 'Chilled Calamansi Juice',
                'description' => 'Native citrus juice freshly squeezed and chilled, sweetened with pure wild mountain honey.',
                'price' => 25.00,
                'daily_stock_limit' => 50,
                'current_stock' => 50,
                'is_sold_out' => false,
            ],
            [
                'category_id' => $drinks->id,
                'name' => 'Sago\'t Gulaman',
                'description' => 'Traditional Filipino cooler made with soft grass jelly cubes, tapioca pearls, and brown sugar syrup over ice.',
                'price' => 30.00,
                'daily_stock_limit' => 50,
                'current_stock' => 50,
                'is_sold_out' => false,
            ],
        ];

        foreach ($items as $item) {
            MenuItem::firstOrCreate(
                ['name' => $item['name']],
                $item
            );
        }
    }
}
