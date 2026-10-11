<?php

namespace App\Modules\Organization\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\IAM\Application\AuthorizationDirectory;

class EmployeePolicy
{
    public function __construct(private AuthorizationDirectory $authorization) {}

    /** @param array<string, mixed> $employee */
    public function view(User $user, array $employee): bool
    {
        return $this->authorization->allows($user->id, 'organization.empleados.consultar', $employee['identityId']);
    }

    /** @param array<string, mixed> $employee */
    public function degrees(User $user, array $employee): bool
    {
        return in_array($employee['status'], ['ACTIVO', 'EN_LICENCIA'], true)
            && $this->authorization->allows($user->id, 'organization.grados.gestionar', $employee['identityId']);
    }

    public function capability(User $user, string $permission): bool
    {
        return $this->authorization->allows($user->id, $permission);
    }
}
