<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('menu_items') && ! Schema::hasColumn('menu_items', 'active_date')) {
            Schema::table('menu_items', function (Blueprint $table) {
                $table->date('active_date')->nullable()->after('is_active');
            });

            DB::table('menu_items')
                ->where('is_active', true)
                ->whereNull('active_date')
                ->update(['active_date' => now()->toDateString()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('menu_items') && Schema::hasColumn('menu_items', 'active_date')) {
            Schema::table('menu_items', function (Blueprint $table) {
                $table->dropColumn('active_date');
            });
        }
    }
};
