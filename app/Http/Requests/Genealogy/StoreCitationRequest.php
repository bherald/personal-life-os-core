<?php

namespace App\Http\Requests\Genealogy;

use App\Services\Genealogy\SourceCitationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form request validation for creating a new citation
 *
 * Extracted from GenealogyController as part of Priority 2.3
 *
 * @see /docs/genealogy-module-review.md Priority 2.3
 */
class StoreCitationRequest extends FormRequest
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
            'source_id' => 'required|integer|exists:genealogy_sources,id',
            'person_id' => 'nullable|integer|exists:genealogy_persons,id',
            'family_id' => 'nullable|integer|exists:genealogy_families,id',
            'media_id' => 'nullable|integer|exists:genealogy_media,id',
            'fact_type' => 'nullable|string|max:50',
            'page' => 'nullable|string|max:255',
            'quality' => 'nullable|integer|min:0|max:3',
            'text' => 'nullable|string|max:10000',
            'note' => 'nullable|string|max:5000',
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
            'source_id.required' => 'Source is required for citation',
            'source_id.exists' => 'The selected source does not exist',
            'person_id.exists' => 'The selected person does not exist',
            'family_id.exists' => 'The selected family does not exist',
            'media_id.exists' => 'The selected media item does not exist',
            'quality.min' => 'Quality must be between 0 (unreliable) and 3 (primary)',
            'quality.max' => 'Quality must be between 0 (unreliable) and 3 (primary)',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param Validator $validator
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            // Must have at least one of person_id, family_id, or media_id
            if (!$this->person_id && !$this->family_id && !$this->media_id) {
                $validator->errors()->add(
                    'citation',
                    'Citation must be linked to a person, family, or media item'
                );
            }

            // Validate fact_type against known types if provided
            if ($this->fact_type) {
                $validTypes = array_keys(SourceCitationService::CITATION_FACT_TYPES);
                if (!in_array($this->fact_type, $validTypes)) {
                    // Allow custom fact types but add a warning in the errors
                    // For now, we just validate it's a non-empty string
                }
            }
        });
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
