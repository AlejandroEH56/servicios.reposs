<?php

namespace App\Modules\Residencies\Presentation\Http\Requests\Api\V1;

use App\Modules\Residencies\Domain\Services\DocumentRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('operation') !== 'stage-resource') {
            abort_unless($this->isJson(), 415);
        }
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return match ($this->route('operation')) {
            'stage-resource' => ['purpose' => ['required', 'in:TEMPLATE_RESOURCE'], 'file' => ['required', 'file', 'max:512', 'mimetypes:image/png,image/jpeg', 'dimensions:max_width=2000,max_height=2000']],
            'template-version' => ['type' => ['required', Rule::in(DocumentRules::TYPES)], 'name' => ['required', 'string', 'max:160'],
                'expectedPreviousVersion' => ['required', 'integer', 'min:1', 'max:65534'], 'active' => ['required', 'boolean'],
                'departmentSignerId' => ['nullable', 'ulid'], 'divisionSignerId' => ['nullable', 'ulid'],
                'departmentTitle' => ['sometimes', 'required', 'string', 'max:180'], 'divisionTitle' => ['sometimes', 'required', 'string', 'max:180'],
                'resourceIds' => ['sometimes', 'array', 'max:6'], 'resourceIds.*' => ['required', 'ulid', 'distinct']],
            'issue' => ['type' => ['required', Rule::in(DocumentRules::TYPES)], 'templateId' => ['required', 'ulid'], 'expectedTemplateVersion' => ['required', 'integer', 'min:1', 'max:65535'],
                'recipientContactId' => ['nullable', 'ulid', 'prohibited_unless:type,SOLICITUD_RESIDENCIA'],
                'reportSequence' => ['required_if:type,FORMATO_REPORTE_PARCIAL,FORMATO_REPORTE_FINAL', 'prohibited_unless:type,FORMATO_REPORTE_PARCIAL,FORMATO_REPORTE_FINAL', 'integer', 'min:1', 'max:3'],
                'adviserAssignmentId' => ['required_if:type,CONSTANCIA_ASESORIA', 'prohibited_unless:type,CONSTANCIA_ASESORIA', 'ulid'],
                'year' => ['required_if:type,CONSTANCIA_ASESORIA', 'prohibited_unless:type,CONSTANCIA_ASESORIA', 'integer', 'min:2000', 'max:2199'],
                'semester' => ['required_if:type,CONSTANCIA_ASESORIA', 'prohibited_unless:type,CONSTANCIA_ASESORIA', 'integer', 'in:1,2'],
                'startsOn' => ['required_if:type,CONSTANCIA_ASESORIA', 'prohibited_unless:type,CONSTANCIA_ASESORIA', 'date_format:Y-m-d'],
                'endsOn' => ['required_if:type,CONSTANCIA_ASESORIA', 'prohibited_unless:type,CONSTANCIA_ASESORIA', 'date_format:Y-m-d']],
            'annul' => ['observations' => ['required', 'string', 'max:5000'], 'expectedVersion' => ['required', 'integer', 'min:1']],
            'retry' => ['expectedVersion' => ['required', 'integer', 'min:1']],default => abort(404)
        };
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
