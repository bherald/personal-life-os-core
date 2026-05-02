<?php

namespace App\Http\Requests\Genealogy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form request validation for creating a new family
 *
 * Extracted from GenealogyController as part of Priority 2.3
 *
 * @see /docs/genealogy-module-review.md Priority 2.3
 */
class StoreFamilyRequest extends FormRequest
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
            'husband_id' => 'nullable|integer|exists:genealogy_persons,id',
            'wife_id' => 'nullable|integer|exists:genealogy_persons,id',
            'marriage_date' => 'nullable|string|max:50',
            'marriage_place' => 'nullable|string|max:500',
            'divorce_date' => 'nullable|string|max:50',
            'divorce_place' => 'nullable|string|max:500',
            'child_ids' => 'nullable|array',
            'child_ids.*' => 'integer|exists:genealogy_persons,id',
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
            'husband_id.exists' => 'The selected husband does not exist in this tree',
            'wife_id.exists' => 'The selected wife does not exist in this tree',
            'child_ids.*.exists' => 'One or more selected children do not exist',
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

    /**
     * Configure the validator instance.
     *
     * @param Validator $validator
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            // Validate that at least one spouse is specified
            if (!$this->husband_id && !$this->wife_id) {
                $validator->errors()->add(
                    'family',
                    'At least one spouse (husband or wife) must be specified'
                );
            }

            // Validate that husband and wife are different people
            if ($this->husband_id && $this->wife_id && $this->husband_id === $this->wife_id) {
                $validator->errors()->add(
                    'family',
                    'Husband and wife cannot be the same person'
                );
            }
        });
    }
}
