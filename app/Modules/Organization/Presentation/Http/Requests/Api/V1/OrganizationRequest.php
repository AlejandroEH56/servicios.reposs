<?php

namespace App\Modules\Organization\Presentation\Http\Requests\Api\V1;

use App\Modules\Organization\Application\OrganizationDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class OrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $action = $this->route('operation');
        if (in_array($action, ['addDegree', 'removeDegree'], true)) {
            return Gate::allows('organization.employee.degrees', [app(OrganizationDirectory::class)->employee($this->route('employee'))]);
        }
        $permission = match ($action) {
            'createUnit', 'updateUnit' => 'organization.unidades.gestionar',
            'createLevel', 'updateLevel' => 'organization.niveles.gestionar',
            default => 'organization.empleados.gestionar',
        };

        return Gate::allows('organization.capability', [$permission]);
    }

    protected function prepareForValidation(): void
    {
        foreach (['position' => 'positionId', 'degree' => 'degreeId'] as $route => $field) {
            if ($this->route($route) !== null) {
                $this->merge([$field => $this->route($route)]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $version = ['expectedVersion' => ['required', 'integer', 'min:1']];
        $employee = [
            'givenNames' => ['required', 'string', 'max:160'], 'firstSurname' => ['required', 'string', 'max:120'],
            'secondSurname' => ['nullable', 'string', 'max:120'], 'employeeNumber' => ['nullable', 'string', 'max:40'],
            'isAdviser' => ['required', 'boolean'], 'email' => ['prohibited'],
        ];
        $unit = ['code' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9_\-]+$/'], 'name' => ['required', 'string', 'max:180'],
            'kind' => ['required', 'string', 'max:40'], 'active' => ['required', 'boolean'], 'parentId' => ['nullable', 'ulid']];
        $level = ['code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_\-]+$/'], 'name' => ['required', 'string', 'max:100'],
            'rank' => ['required', 'integer', 'min:1', 'max:65535']];

        return match ($this->route('operation')) {
            'createEmployee' => $employee + ['identityId' => ['required', 'ulid']],
            'updateEmployee' => $employee + $version + ['status' => ['required', 'in:ACTIVO,EN_LICENCIA,INACTIVO,ARCHIVADO'], 'identityId' => ['prohibited']],
            'createUnit' => $unit,
            'updateUnit' => $unit + $version,
            'createLevel' => $level,
            'updateLevel' => $level + $version,
            'assignPosition' => $version + ['unitId' => ['required', 'ulid'], 'name' => ['required', 'string', 'max:180'],
                'startsOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'source' => ['prohibited']],
            'finishPosition' => $version + ['positionId' => ['required', 'ulid'], 'endsOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']],
            'addDegree' => $version + ['levelId' => ['required', 'ulid'], 'program' => ['required', 'string', 'max:255'],
                'abbreviation' => ['nullable', 'string', 'max:45'], 'obtainedOn' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']],
            'removeDegree' => $version + ['degreeId' => ['required', 'ulid']],
            default => $version,
        };
    }
}
