<?php

namespace App\Modules\LaboratoryRequests\Infrastructure\Persistence;

use App\Modules\AcademicPlanning\Application\PlanningDirectory;
use App\Modules\IAM\Application\AuthorizationDirectory;
use App\Modules\Organization\Application\OrganizationDirectory;

class RequestAccess
{
    public function __construct(
        private AuthorizationDirectory $authorization,
        private PlanningDirectory $planning,
        private OrganizationDirectory $organization,
    ) {}

    public function globalReviewer(string $actor): bool
    {
        return $this->authorization->allows($actor, 'laboratorios.solicitudes.revisar');
    }

    /** @return list<string> */
    public function laboratories(string $actor): array
    {
        if (! $this->authorization->allows($actor, 'laboratorios.solicitudes.revisar', $actor)) {
            return [];
        }
        $employee = $this->organization->employees($actor, '')[0] ?? null;
        if ($employee === null || $employee['status'] !== 'ACTIVO') {
            return [];
        }

        return $this->planning->responsibleLaboratoryIds($employee['id']);
    }

    public function canReview(string $actor, string $laboratory): bool
    {
        return $this->globalReviewer($actor) || in_array($laboratory, $this->laboratories($actor), true);
    }

    public function view(string $actor, object $row): void
    {
        abort_unless($actor === $row->id_identidad_solicitante || $this->canReview($actor, $row->id_laboratorio), 404);
    }
}
