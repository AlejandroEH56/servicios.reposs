<?php

namespace App\Modules\IAM\Application;

interface IdentityProvisioner
{
    public function provision(VerifiedIdentity $identity, string $correlationId): string;
}
