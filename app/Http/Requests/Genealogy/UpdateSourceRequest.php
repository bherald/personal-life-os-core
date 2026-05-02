<?php

namespace App\Http\Requests\Genealogy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form request validation for updating a source
 *
 * Extracted from GenealogyController as part of Priority 2.3
 *
 * @see /docs/genealogy-module-review.md Priority 2.3
 */
class UpdateSourceRequest extends FormRequest
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
            'title' => 'sometimes|required|string|max:500',
            'author' => 'sometimes|nullable|string|max:500',
            'publication' => 'sometimes|nullable|string|max:1000',
            'abbreviation' => 'sometimes|nullable|string|max:100',
            'text' => 'sometimes|nullable|string|max:10000',
            'repository_id' => 'sometimes|nullable|integer|exists:genealogy_repositories,id',
            'call_number' => 'sometimes|nullable|string|max:100',
            'url' => 'sometimes|nullable|url|max:500',
            'notes' => 'sometimes|nullable|string|max:5000',
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
            'title.required' => 'Source title is required',
            'title.max' => 'Source title must be 500 characters or less',
            'repository_id.exists' => 'The selected repository does not exist',
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
