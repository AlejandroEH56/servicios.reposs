<?php

namespace App\Modules\LaboratoryRequests\Presentation\Http\Requests\Api\V1;

use App\Modules\LaboratoryRequests\Domain\Services\RequestRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CaptureRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(RequestRules::DETAILS))],
            'laboratoryId' => ['required', 'ulid'],
            'startAt' => ['required', 'string', 'max:40'],
            'endAt' => ['required', 'string', 'max:40'],
            'title' => ['required', 'string', 'min:2', 'max:255'],
            'purpose' => ['required', 'string', 'min:2', 'max:255'],
            'attendees' => ['required', 'integer:strict', 'min:1', 'max:65535'],
            'supplies' => ['sometimes', 'nullable', 'string', 'min:2', 'max:10000'],
            'practice' => ['required_if:type,PRACTICA', 'array:careerId,subjectId,groupId,name,number'],
            'practice.careerId' => ['required_with:practice', 'ulid'],
            'practice.subjectId' => ['required_with:practice', 'ulid'],
            'practice.groupId' => ['required_with:practice', 'ulid'],
            'practice.name' => ['required_with:practice', 'string', 'min:2', 'max:255'],
            'practice.number' => ['sometimes', 'nullable', 'string', 'max:40'],
            'various' => ['required_if:type,VARIA', 'array:useCode,description'],
            'various.useCode' => ['required_with:various', Rule::in(['PROYECTO', 'ACTIVIDAD', 'OTRO'])],
            'various.description' => ['required_with:various', 'string', 'min:2', 'max:255'],
            'extraordinary' => ['required_if:type,EXTRAORDINARIA', 'array:justification'],
            'extraordinary.justification' => ['required_with:extraordinary', 'string', 'min:2', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $this->all();
            $type = $data['type'] ?? null;
            $expected = is_string($type) ? (RequestRules::DETAILS[$type] ?? null) : null;
            foreach (RequestRules::DETAILS as $field) {
                if ($field !== $expected && array_key_exists($field, $data)) {
                    $validator->errors()->add($field, 'Este detalle no corresponde al tipo de solicitud.');
                }
            }
            foreach (array_diff(array_keys($data), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Campo no permitido.');
            }
        });
    }
}
