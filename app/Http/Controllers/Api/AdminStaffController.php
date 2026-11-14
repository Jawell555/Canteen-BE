<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminStaffController extends Controller
{
    /**
     * List all staff and admin accounts.
     */
    public function index(): JsonResponse
    {
        $staff = User::whereIn('role', ['staff', 'admin'])
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'email', 'phone_number', 'role', 'is_active', 'created_at']);

        return ApiResponse::success([
            'staff' => $staff,
        ], 'Staff accounts retrieved successfully');
    }

    /**
     * Create a new staff account.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6',
            'phone_number' => 'nullable|string|max:30',
            'role' => 'nullable|string|in:staff,admin',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => strtolower(trim($validated['email'])),
            'phone_number' => $validated['phone_number'] ?? null,
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'] ?? 'staff',
            'is_active' => true,
        ]);

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'create_staff_account',
            'entity_type' => 'users',
            'entity_id' => (string) $user->id,
            'details' => ['name' => $user->name, 'email' => $user->email, 'role' => $user->role],
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success($user, 'Staff account created successfully', 201);
    }

    /**
     * Toggle active status of a staff user. Revoke all tokens if deactivated.
     */
    public function toggleActive(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->is_active = ! $user->is_active;
        $user->save();

        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'toggle_staff_active',
            'entity_type' => 'users',
            'entity_id' => (string) $user->id,
            'details' => ['is_active' => $user->is_active],
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success([
            'user' => $user,
            'is_active' => $user->is_active,
        ], 'Staff account status updated');
    }
}
