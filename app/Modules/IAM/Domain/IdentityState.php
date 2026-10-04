<?php

namespace App\Modules\IAM\Domain;

enum IdentityState: string
{
    case Pending = 'PENDIENTE';
    case Active = 'ACTIVA';
    case Suspended = 'SUSPENDIDA';
    case Deactivated = 'DESACTIVADA';

    public function canLogin(): bool
    {
        return $this === self::Active;
    }
}
