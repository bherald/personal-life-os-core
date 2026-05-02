<?php

namespace App\Http\Requests\Genealogy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form request validation for updating a person
 *
 * Extracted from GenealogyController as part of Priority 2.3
 *
 * @see /docs/genealogy-module-review.md Priority 2.3
 */
class UpdatePersonRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled at controller/service level
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'given_name' => 'sometimes|nullable|string|max:255',
            'surname' => 'sometimes|nullable|string|max:255',
            'suffix' => 'sometimes|nullable|string|max:50',
            'prefix' => 'sometimes|nullable|string|max:50',
            'nickname' => 'sometimes|nullable|string|max:255',
            'sex' => 'sometimes|nullable|string|in:M,F,U',
            'birth_date' => 'sometimes|nullable|string|max:50',
            'birth_place' => 'sometimes|nullable|string|max:500',
            'death_date' => 'sometimes|nullable|string|max:50',
            'death_place' => 'sometimes|nullable|string|max:500',
            'occupation' => 'sometimes|nullable|string|max:255',
            'education' => 'sometimes|nullable|string|max:255',
            'religion' => 'sometimes|nullable|string|max:100',
            'note' => 'sometimes|nullable|string|max:10000',
            'privacy_override' => 'sometimes|nullable|string|in:default,public,private,restricted',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sex.in' => 'Sex must be M (Male), F (Female), or U (Unknown)',
            'privacy_override.in' => 'Privacy override must be default, public, private, or restricted',
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param Validator $validator
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => [
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ],
        ], 422));
    }
}
