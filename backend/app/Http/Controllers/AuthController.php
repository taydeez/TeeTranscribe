<?php

namespace App\Http\Controllers;

use App\Domain\Admin\TwoFactor\Services\AdminTwoFactorService;
use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use App\Domain\Auth\Contracts\SocialAuthGatewayInterface;
use App\Domain\Auth\Services\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AuthController extends Controller
{
    public function register(Request $request, AuthenticationService $service): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()]]);

        return response()->json($service->register($data['name'], $data['email'], $data['password']), 201);
    }

    public function login(Request $request, AuthenticationService $service, AdminTwoFactorService $twoFactor): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $result = $service->login($data['email'], $data['password']);
        if ($result['is_admin']) {
            $twoFactor->send($result['user']->id, $result['user']->email);

            return response()->json(['requires_two_factor' => true, 'email' => $result['user']->email]);
        }

        return response()->json($result);
    }

    public function verifyAdmin(Request $request, AdminTwoFactorService $service): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'digits:6']]);
        try {
            return response()->json(['token' => $service->verify($data['email'], $data['code'])]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function googleRedirect(SocialAuthGatewayInterface $google): RedirectResponse
    {
        return redirect()->away($google->authorizationUrl());
    }

    public function googleCallback(AuthRepositoryInterface $users, SocialAuthGatewayInterface $google): RedirectResponse
    {
        $identity = $google->identityFromCallback();
        $user = $users->upsertGoogle($identity->providerId, $identity->name, $identity->email);
        $token = $users->issueToken($user->id, 'google');

        return redirect()->away(rtrim((string) config('app.frontend_url', 'http://127.0.0.1:3000'), '/').'/#token='.rawurlencode($token));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
