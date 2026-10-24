<?php

namespace App\Modules\AcademicPlanning\Presentation\Http\Requests\Api\V1;

use App\Modules\IAM\Application\AuthorizationDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PlanningRequest extends FormRequest
{
    public function kind(): string
    {
        $kind = $this->route('kind') ?? $this->route('catalog');
        abort_unless(in_array($kind, ['careers', 'specialties', 'subjects', 'curricula', 'groups', 'holiday-types', 'semesters', 'laboratories', 'holidays'], true), 404);

        return $kind;
    }

    public function authorize(): bool
    {
        $permission = in_array($this->kind(), ['semesters', 'laboratories', 'holidays'], true) ? 'planning.manage' : 'planning.catalogs.manage';

        return app(AuthorizationDirectory::class)->allows((string) $this->user()?->getAuthIdentifier(), $permission);
    }

    protected function prepareForValidation(): void
    {
        abort_unless($this->isJson(), 415);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if (! in_array($this->route('operation'), ['create', 'update'], true)) {
            return [];
        }
        $fields = match ($this->kind()) {
            'careers' => ['code', 'name', 'shortName', 'active'],
            'specialties' => ['careerId', 'code', 'name', 'active'],
            'subjects' => ['code', 'name', 'satca', 'active'],
            'curricula' => ['careerId', 'specialtyId', 'subjectId', 'active'],
            'groups' => ['careerId', 'semesterId', 'name', 'active'],
            'holiday-types' => ['code', 'name', 'active'],
            'semesters' => ['code', 'name', 'startsOn', 'endsOn'],
            'laboratories' => ['code', 'name', 'careerId', 'capacity', 'responsibleEmployeeId', 'status'],
            'holidays' => ['holidayTypeId', 'laboratoryId', 'name', 'startsAt', 'endsAt', 'active'],
            default => abort(404),
        };
        $rules = [];
        foreach ($fields as $field) {
            $rules[$field] = match ($field) {
                'code' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9._-]{0,39}$/D'],
                'name' => ['required', 'string', 'max:100'],
                'shortName' => ['nullable', 'string', 'max:80'],
                'satca' => ['nullable', 'string', 'regex:/^\d{1,2}-\d{1,2}-\d{1,2}$/D'],
                'active' => ['required', 'boolean'],
                'capacity' => ['required', 'integer', 'min:1', 'max:65535'],
                'status' => ['required', 'in:ACTIVO,MANTENIMIENTO,INACTIVO,ARCHIVADO'],
                'startsOn', 'endsOn' => ['required', 'date_format:Y-m-d'],
                'startsAt', 'endsAt' => ['required', 'string', 'max:40'],
                'specialtyId', 'responsibleEmployeeId', 'laboratoryId' => ['nullable', 'ulid'],
                'careerId' => [$this->kind() === 'laboratories' ? 'nullable' : 'required', 'ulid'],
                default => ['required', 'ulid'],
            };
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Campo no permitido.');
            }
        });
    }
}
