<?php

namespace App\Http\Responses\Auth;

use App\Domain\Admin\Auth\Services\AdminAccess;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AuthResponse extends ApiResponse
{
    public function adminChallenge(array $result): array
    {
        return ['requires_two_factor' => true, 'email' => $result['user']->email];
    }

    public function invalidAdminCode(string $message): array
    {
        return ['message' => $message];
    }

    public function loggedOut(): array
    {
        return ['message' => 'Logged out.'];
    }

    public function profileUpdated(): array
    {
        return ['message' => 'Your name has been updated.'];
    }

    public function incorrectPassword(): array
    {
        return ['message' => 'Your current password is incorrect.'];
    }

    public function passwordChanged(): array
    {
        return ['message' => 'Your password has been changed. Please log in again.'];
    }

    public function resetLinkSent(): array
    {
        return ['message' => 'If an account exists for that email, a password reset link has been sent.'];
    }

    public function invalidResetLink(): array
    {
        return ['message' => 'This password reset link is invalid or has expired. Request a new link.'];
    }

    public function passwordReset(): array
    {
        return ['message' => 'Your password has been reset. Please log in with your new password.'];
    }

    public function verificationSent(): array
    {
        return ['message' => 'If your email needs verification, a verification link has been sent.'];
    }

    public function invalidVerificationLink(): array
    {
        return ['message' => 'This email verification link is invalid.'];
    }

    public function emailVerified(): array
    {
        return ['message' => 'Your email address has been verified.'];
    }

    public function adminToken(string $token): JsonResponse
    {
        return $this->json(['token' => $token]);
    }

    public function adminEmailChallenge(string $email): JsonResponse
    {
        return $this->json(['requires_two_factor' => true, 'email' => $email]);
    }

    public function googleCallback(string $token): RedirectResponse
    {
        return $this->away(rtrim((string) config('app.frontend_url', 'http://127.0.0.1:3000'), '/').'/#token='.rawurlencode($token));
    }

    public function user(Request $request, bool $admin = false): JsonResponse
    {
        return $this->json(array_merge($request->user()->toArray(), [
            'roles' => $request->user()->getRoleNames()->all(),
            'is_admin' => $request->user()->hasAnyRole(AdminAccess::ROLES),
            'permissions' => $request->user()->hasAnyRole(AdminAccess::ROLES)
                ? $request->user()->getAllPermissions()->pluck('name')->all() : [],
            'email_verified' => $request->user()->hasVerifiedEmail(),
        ]));
    }

    public function adminActivity(string $expiresAt): JsonResponse
    {
        return $this->json(['expires_at' => $expiresAt])->header('Cache-Control', 'private, no-store');
    }

    public function googleAdminChallenge(string $email): RedirectResponse
    {
        return $this->away(rtrim((string) config('app.frontend_url', 'http://127.0.0.1:3000'), '/').'/taydeez/login?verify=1&email='.rawurlencode($email));
    }

    public function guestSession(string $id): JsonResponse
    {
        return $this->json(['id' => $id]);
    }
}
