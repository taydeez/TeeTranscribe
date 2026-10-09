<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use App\Domain\Auth\Contracts\SocialAuthGatewayInterface;
use App\Http\Responses\Auth\AuthResponse;
use Illuminate\Http\RedirectResponse;

final class GoogleCallbackController
{
    public function __invoke(AuthRepositoryInterface $users, SocialAuthGatewayInterface $google, AuthResponse $response): RedirectResponse
    {
        $identity = $google->identityFromCallback();
        $user = $users->upsertGoogle($identity->providerId, $identity->name, $identity->email);
        $token = $users->issueToken($user->id, 'google');

        return $response->googleCallback($token);
    }
}
