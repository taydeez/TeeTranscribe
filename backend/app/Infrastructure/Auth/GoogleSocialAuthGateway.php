<?php

namespace App\Infrastructure\Auth;

use App\Domain\Auth\Contracts\SocialAuthGatewayInterface;
use App\Domain\Auth\Entities\SocialIdentity;
use Laravel\Socialite\Facades\Socialite;

class GoogleSocialAuthGateway implements SocialAuthGatewayInterface
{
    public function authorizationUrl(): string
    {
        return Socialite::driver('google')->stateless()->redirect()->getTargetUrl();
    }

    public function identityFromCallback(): SocialIdentity
    {
        $user = Socialite::driver('google')->stateless()->user();

        return new SocialIdentity($user->getId(), $user->getName() ?: 'Google user', $user->getEmail());
    }
}
