<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Auth\Services\AdminAccess;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class RequireAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->hasAnyRole(AdminAccess::ROLES), 403);
        $token = $request->user()->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || $token->name !== 'admin'
            || ! in_array(AdminAccess::TOKEN_ABILITY, $token->abilities, true)
            || $token->expires_at === null || $token->expires_at->lessThanOrEqualTo(now())) {
            throw new AuthenticationException('Please complete administrator email verification.');
        }

        return $next($request);
    }
}
