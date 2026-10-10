<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Contracts\SocialAuthGatewayInterface;
use App\Http\Responses\Auth\AuthResponse;
use Illuminate\Http\RedirectResponse;

final class GoogleRedirectController
{
    public function __invoke(SocialAuthGatewayInterface $google, AuthResponse $response): RedirectResponse
    {
        return $response->away($google->authorizationUrl());
    }
}
