<?php

namespace App\Modules\Residencies\Presentation\Http\Requests\Api\V1;

use App\Modules\Residencies\Domain\Services\EvidenceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('operation') !== 'stage') {
            abort_unless($this->isJson(), 415);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $scope = ['residentId' => ['required', 'ulid'], 'projectId' => ['nullable', 'ulid'], 'requirementId' => ['required', 'ulid']];

        return match ($this->route('operation')) {
            'define' => [
                'code' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9._-]{0,79}$/D'], 'name' => ['required', 'string', 'max:255'],
                'programId' => ['nullable', 'ulid'], 'stage' => ['required', 'in:PRERREQUISITO,REQUISITO'], 'scope' => ['required', 'in:RESIDENTE,PROYECTO'],
                'required' => ['required', 'boolean'], 'displayOrder' => ['required', 'integer', 'min:0', 'max:65535'],
                'validFrom' => ['nullable', 'date_format:Y-m-d'], 'validUntil' => ['nullable', 'date_format:Y-m-d'], 'active' => ['required', 'boolean'],
                'allowedMimes' => ['required', 'array', 'min:1', 'max:4'], 'allowedMimes.*' => ['required', 'string', 'distinct', Rule::in(EvidenceRules::MIMES)],
                'maxBytes' => ['required', 'integer', 'min:1', 'max:'.config('modernization.storage.max_bytes')],
            ],
            'submit' => $scope + ['stagedFileId' => ['required', 'ulid'], 'expectedSubmissionVersion' => ['required', 'integer', 'min:0', 'max:65534']],
            'review' => ['decision' => ['required', 'in:APROBADO,RECHAZADO,CAMBIOS_SOLICITADOS'], 'observations' => ['nullable', 'string', 'max:5000', 'required_if:decision,RECHAZADO,CAMBIOS_SOLICITADOS'],
                'grade' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'], 'expectedReviewNumber' => ['required', 'integer', 'min:1']],
            'stage' => $scope + ['purpose' => ['required', 'in:RESIDENCY_EVIDENCE'], 'file' => ['required', 'file', 'max:10240', 'mimetypes:'.implode(',', EvidenceRules::MIMES)]],
            default => abort(404),
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
