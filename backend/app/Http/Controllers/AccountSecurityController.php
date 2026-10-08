<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Services\AccountSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

final class AccountSecurityController extends Controller
{
    public function updateProfile(Request $request, AccountSecurityService $service): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $service->updateName((int) $request->user()->getAuthIdentifier(), $data['name']);

        return response()->json(['message' => 'Your name has been updated.']);
    }

    public function changePassword(Request $request, AccountSecurityService $service): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        if (! $service->changePassword((int) $request->user()->getAuthIdentifier(), $data['current_password'], $data['password'])) {
            return response()->json(['message' => 'Your current password is incorrect.'], 422);
        }

        return response()->json(['message' => 'Your password has been changed. Please log in again.']);
    }

    public function forgotPassword(Request $request, AccountSecurityService $service): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $service->requestPasswordReset($data['email']);

        return response()->json(['message' => 'If an account exists for that email, a password reset link has been sent.']);
    }

    public function resetPassword(Request $request, AccountSecurityService $service): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        if (! $service->resetPassword($data['email'], $data['token'], $data['password'])) {
            return response()->json(['message' => 'This password reset link is invalid or has expired. Request a new link.'], 422);
        }

        return response()->json(['message' => 'Your password has been reset. Please log in with your new password.']);
    }

    public function resendVerification(Request $request, AccountSecurityService $service): JsonResponse
    {
        $service->sendVerification((int) $request->user()->getAuthIdentifier());

        return response()->json(['message' => 'If your email needs verification, a verification link has been sent.']);
    }

    public function verifyEmail(int $id, string $hash, AccountSecurityService $service): JsonResponse
    {
        if (! $service->verifyEmail($id, $hash)) {
            return response()->json(['message' => 'This email verification link is invalid.'], 403);
        }

        return response()->json(['message' => 'Your email address has been verified.']);
    }
}
