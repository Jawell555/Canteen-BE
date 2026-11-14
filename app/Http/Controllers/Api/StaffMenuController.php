<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffMenuController extends Controller
{
    /**
     * Quick toggle sold-out status of a menu item during peak rush.
     */
    public function toggleSoldOut(Request $request, int $id): JsonResponse
    {
        $menuItem = MenuItem::findOrFail($id);

        if ($request->has('is_sold_out')) {
            $menuItem->is_sold_out = (bool) $request->input('is_sold_out');
        } else {
            $menuItem->is_sold_out = ! $menuItem->is_sold_out;
        }

        $menuItem->save();

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'toggle_sold_out',
            'entity_type' => 'menu_items',
            'entity_id' => (string) $menuItem->id,
            'details' => [
                'item_name' => $menuItem->name,
                'is_sold_out' => $menuItem->is_sold_out,
            ],
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success([
            'menu_item' => $menuItem,
            'is_sold_out' => $menuItem->is_sold_out,
        ], 'Item sold-out status updated successfully');
    }
}
