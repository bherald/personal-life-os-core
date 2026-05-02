<?php

namespace App\Http\Requests\Genealogy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form request validation for creating a new person
 *
 * Extracted from GenealogyController as part of Priority 2.3
 *
 * @see /docs/genealogy-module-review.md Priority 2.3
 */
class StorePersonRequest extends FormRequest
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
            'given_name' => 'nullable|string|max:255',
            'surname' => 'nullable|string|max:255',
            'suffix' => 'nullable|string|max:50',
            'prefix' => 'nullable|string|max:50',
            'nickname' => 'nullable|string|max:255',
            'sex' => 'nullable|string|in:M,F,U',
            'birth_date' => 'nullable|string|max:50',
            'birth_place' => 'nullable|string|max:500',
            'death_date' => 'nullable|string|max:50',
            'death_place' => 'nullable|string|max:500',
            'occupation' => 'nullable|string|max:255',
            'education' => 'nullable|string|max:255',
            'religion' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:10000',
            'privacy_override' => 'nullable|string|in:default,public,private,restricted',
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
            'birth_date.max' => 'Birth date exceeds maximum length',
            'death_date.max' => 'Death date exceeds maximum length',
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
