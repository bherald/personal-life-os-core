<?php

namespace App\Http\Requests\Genealogy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form request validation for creating a new repository
 *
 * Extracted from GenealogyController as part of Priority 2.3
 *
 * @see /docs/genealogy-module-review.md Priority 2.3
 */
class StoreRepositoryRequest extends FormRequest
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
            'name' => 'required|string|max:500',
            'address' => 'nullable|string|max:1000',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:500',
            'url' => 'nullable|url|max:500', // Alias for website
            'note' => 'nullable|string|max:5000',
            'notes' => 'nullable|string|max:5000', // Alias for note
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
            'name.required' => 'Repository name is required',
            'name.max' => 'Repository name must be 500 characters or less',
            'email.email' => 'Please enter a valid email address',
            'website.url' => 'Please enter a valid URL for the website',
            'url.url' => 'Please enter a valid URL',
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
