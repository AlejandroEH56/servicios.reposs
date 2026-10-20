<?php

namespace App\Modules\Publications\Presentation\Http\Requests\Api\V1;

use App\Modules\Publications\Application\NoticeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class NoticeRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(NoticeService::class)->authorize($this->user()?->getAuthIdentifier());

        return true;
    }

    protected function prepareForValidation(): void
    {
        abort_unless($this->route('operation') === 'stage' || $this->isJson(), 415);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->route('operation')) {
            'stage' => ['file' => ['required', 'file', 'max:2048'], 'purpose' => ['required', 'in:NOTICE_IMAGE']],
            'publish', 'withdraw' => [],
            default => ['type' => ['required', 'in:RESIDENCIAS,SERVICIO_SOCIAL,EMPLEO'], 'title' => ['required', 'string', 'max:200'],
                'description' => ['required', 'string', 'max:12000'], 'requirements' => ['nullable', 'string', 'max:6000'],
                'url' => ['nullable', 'string', 'max:2048'], 'imageId' => ['nullable', 'ulid'],
                'publicationWindow' => ['sometimes', 'array:startsAt,endsAt'], 'publicationWindow.startsAt' => ['nullable', 'string'],
                'publicationWindow.endsAt' => ['nullable', 'string']],
        };
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = array_map(fn (string $key) => explode('.', $key)[0], array_keys($this->rules()));
            foreach (array_keys($this->all()) as $key) {
                if (! in_array($key, $allowed, true)) {
                    $validator->errors()->add($key, 'Campo no permitido.');
                }
            }
        });
    }
}
