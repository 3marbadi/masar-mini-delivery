<?php

namespace App\Http\Requests\Integration;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The credential pair Masar presents (CONTRACT §3.21.2).
 *
 * Shape only. Whether the pair is *correct* is decided in the controller and
 * answered `401`, never `422`: telling a caller that a client id is well-formed
 * but unknown is a different answer from telling it the password was wrong, and
 * neither should be distinguishable from outside.
 */
class IssueMasarTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'string', 'max:100'],
            'client_secret' => ['required', 'string', 'max:255'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'The credential request is malformed.',
                'fields' => $validator->errors()->toArray(),
            ],
            'request_id' => $this->attributes->get('request_id'),
        ], 422));
    }
}
