<?php

namespace App\Modules\AcademicPlanning\Presentation\Http\Requests\Api\V1;

use App\Modules\IAM\Application\AuthorizationDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PublishScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(AuthorizationDirectory::class)->allows((string) $this->user()?->getAuthIdentifier(), 'planning.manage');
    }

    protected function prepareForValidation(): void
    {
        abort_unless($this->isJson(), 415);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['semesterId' => ['required', 'ulid'], 'weekday' => ['required', 'integer', 'min:1', 'max:7'],
            'startsAtLocal' => ['required', 'date_format:H:i'], 'endsAtLocal' => ['required', 'date_format:H:i']];
        if ($this->isMethod('PATCH')) {
            $rules['active'] = ['required', 'boolean'];
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
