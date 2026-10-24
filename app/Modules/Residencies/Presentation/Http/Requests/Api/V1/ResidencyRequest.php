<?php

namespace App\Modules\Residencies\Presentation\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ResidencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        abort_unless($this->isJson(), 415);
        foreach (['rfc', 'curp'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => mb_strtoupper(trim($this->input($field)), 'UTF-8')]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $kind = $this->route('kind');
        $new = $this->isMethod('POST');
        $fields = match ($kind) {
            'modalities','sectors','business-lines' => ['code', 'name', 'active'],
            'programs' => ['code', 'name', 'active', 'modalityId', 'validFrom', 'validUntil'],
            'periods' => ['code', 'name', 'startsOn', 'endsOn', 'durationMonths', 'status'],
            'residents' => [...($new ? ['identityId', 'controlNumber'] : []), 'programId', 'givenNames', 'firstSurname', 'secondSurname', 'address', 'city', 'socialSecurityNumber', 'phone', 'mobilePhone', 'curp'],
            'companies' => ['name', 'rfc', 'sectorId', 'businessLineId', 'mission', 'address', 'city', 'postalCode', 'phone', 'email', 'fax', 'status'],
            'contacts' => ['givenNames', 'firstSurname', 'secondSurname', 'position', 'degree', 'email', 'phone', 'active'],
            'projects' => ['residentId', 'periodId', 'companyId', 'programId', 'name', 'vacancies', 'startsOn', 'endsOn'],
            'internal-advisers','external-advisers' => $new ? [$kind === 'internal-advisers' ? 'employeeId' : 'contactId', 'roleCode', 'startsOn', 'endsOn'] : ['endsOn'],
            default => abort(404),
        };
        $rules = [];
        foreach ($fields as $field) {
            $rules[$field] = match ($field) {
                'code' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9._-]{0,39}$/D'],
                'name' => ['required', 'string', 'max:'.(in_array($kind, ['companies', 'projects'], true) ? 255 : 160)],
                'givenNames' => ['required', 'string', 'max:160'],
                'firstSurname' => ['required', 'string', 'max:120'],
                'secondSurname' => ['nullable', 'string', 'max:120'],
                'controlNumber' => ['required', 'string', 'regex:/^[0-9]{8,9}$/D'],
                'rfc' => ['required', 'string', 'min:12', 'max:13'],
                'curp' => ['nullable', 'string', 'regex:/^[A-Z0-9]{18}$/D'],
                'active' => ['required', 'boolean'],
                'status' => ['required', $kind === 'periods' ? 'in:PLANIFICADO,ACTIVO,CERRADO,ARCHIVADO' : 'in:ACTIVO,INACTIVO,BLOQUEADO'],
                'startsOn' => ['required', 'date_format:Y-m-d'],
                'endsOn' => [in_array($kind, ['internal-advisers', 'external-advisers'], true) && $new ? 'nullable' : 'required', 'date_format:Y-m-d'],
                'validFrom','validUntil' => ['nullable', 'date_format:Y-m-d'],
                'durationMonths' => ['required', 'integer', 'in:4,5,6'],
                'vacancies' => ['required', 'integer', 'min:1', 'max:65535'],
                'roleCode' => ['required', 'in:PRINCIPAL,COLABORADOR'],
                'sectorId','businessLineId' => ['nullable', 'ulid'],
                'email' => ['nullable', 'string', 'email', 'max:254'],
                'mission' => ['nullable', 'string', 'max:2000'],
                'address' => ['nullable', 'string', 'max:500'],
                'position' => ['nullable', 'string', 'max:180'],
                'city','degree' => ['nullable', 'string', 'max:120'],
                'postalCode' => ['nullable', 'string', 'max:12'],
                'phone','mobilePhone','socialSecurityNumber','fax' => ['nullable', 'string', 'max:30'],
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
