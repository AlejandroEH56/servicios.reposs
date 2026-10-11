<?php

namespace App\Modules\IAM\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\IAM\Application\AuthorizationDirectory;

class IamPolicy
{
    public function __construct(private AuthorizationDirectory $authorization) {}

    public function read(User $user): bool
    {
        return $this->authorization->allows($user->id, 'iam.roles.consultar')
            || $this->authorization->allows($user->id, 'iam.roles.gestionar');
    }

    public function manage(User $user): bool
    {
        return $this->authorization->allows($user->id, 'iam.roles.gestionar');
    }
}
