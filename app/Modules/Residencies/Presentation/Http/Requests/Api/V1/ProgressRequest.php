<?php

namespace App\Modules\Residencies\Presentation\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        abort_unless($this->isJson(), 415);
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return match ($this->route('operation')) {
            'plan-initialize' => [],
            'project-approve' => ['expectedVersion' => ['required', 'integer', 'min:1']],
            'report-submit' => ['type' => ['required', 'in:PARCIAL,FINAL'], 'sequence' => ['required', 'integer', 'min:1', 'max:3'],
                'stagedFileId' => ['required', 'ulid'], 'expectedSubmissionVersion' => ['required', 'integer', 'min:0', 'max:65534']],
            'report-review' => ['evidenceId' => ['required', 'ulid'], 'decision' => ['required', 'in:APROBADO,RECHAZADO,CAMBIOS_SOLICITADOS'],
                'observations' => ['nullable', 'string', 'max:5000', 'required_if:decision,RECHAZADO,CAMBIOS_SOLICITADOS'],
                'grade' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100', 'required_if:decision,APROBADO'],
                'expectedReviewNumber' => ['required', 'integer', 'min:1']],
            'evidence-reopen' => ['observations' => ['required', 'string', 'max:5000'], 'expectedReviewNumber' => ['required', 'integer', 'min:1']],
            'release-resolve' => ['decision' => ['required', 'in:APROBADO,RECHAZADO'], 'resolution' => ['nullable', 'string', 'max:255'],
                'observations' => ['required', 'string', 'max:5000'], 'expectedVersion' => ['required', 'integer', 'min:0']],
            'release-reopen' => ['observations' => ['required', 'string', 'max:5000'], 'expectedVersion' => ['required', 'integer', 'min:1']],
            default => abort(404)
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
