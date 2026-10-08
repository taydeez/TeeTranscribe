<?php

namespace App\Infrastructure\Auth;

use App\Domain\Auth\Contracts\SocialAuthGatewayInterface;
use App\Domain\Auth\Entities\SocialIdentity;
use Illuminate\Auth\AuthenticationException;
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
        if (($user->user['email_verified'] ?? false) !== true || ! is_string($user->getEmail()) || $user->getEmail() === '') {
            throw new AuthenticationException('Google must verify your email address before you can sign in.');
        }

        return new SocialIdentity($user->getId(), $user->getName() ?: 'Google user', $user->getEmail());
    }
}
