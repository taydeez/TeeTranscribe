<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Auth\Services\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');
        if ($user !== null && $user->isRestricted()) {
            abort(403, 'Your account is blocked or suspended. Please contact support.');
        }
        if ($user?->must_change_password && $user->hasAnyRole(AdminAccess::ROLES)
            && ! $request->is('api/v1/user', 'api/v1/admin/user', 'api/v1/admin/password', 'api/v1/admin/session/activity', 'api/v1/auth/logout')) {
            return response()->json(['message' => 'Change your initial password before continuing.', 'code' => 'password_change_required'], 403);
        }

        return $next($request);
    }
}
