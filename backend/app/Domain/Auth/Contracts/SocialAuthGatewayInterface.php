<?php

namespace App\Domain\Auth\Contracts;

use App\Domain\Auth\Entities\SocialIdentity;

interface SocialAuthGatewayInterface
{
    public function authorizationUrl(): string;

    public function identityFromCallback(): SocialIdentity;
}
