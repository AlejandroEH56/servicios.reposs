<?php

namespace App\Modules\IAM\Application;

interface EntraAuthenticator
{
    public function authorizationUrl(string $state, string $nonce, string $verifier): string;

    public function authenticate(string $code, string $verifier, string $nonce): VerifiedIdentity;
}
