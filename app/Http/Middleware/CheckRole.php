<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::error('Unauthenticated.', 401);
        }

        if (! $user->is_active) {
            return ApiResponse::error('Your account is deactivated.', 403);
        }

        if (! empty($roles) && ! in_array($user->role, $roles, true)) {
            return ApiResponse::error('Unauthorized. Insufficient permissions.', 403);
        }

        return $next($request);
    }
}
