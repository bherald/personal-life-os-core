<?php

namespace App\Http\Requests\Genealogy;

use App\Services\Genealogy\PersonService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form request validation for creating a new event (person or family)
 *
 * Extracted from GenealogyController as part of Priority 2.3
 *
 * @see /docs/genealogy-module-review.md Priority 2.3
 */
class StoreEventRequest extends FormRequest
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
            'event_type' => 'required|string|max:50',
            'event_date' => 'nullable|string|max:50',
            'event_place' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric|min:-90|max:90',
            'longitude' => 'nullable|numeric|min:-180|max:180',
            'description' => 'nullable|string|max:5000',
            'source_id' => 'nullable|integer|exists:genealogy_sources,id',
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
            'event_type.required' => 'Event type is required',
            'latitude.min' => 'Latitude must be between -90 and 90',
            'latitude.max' => 'Latitude must be between -90 and 90',
            'longitude.min' => 'Longitude must be between -180 and 180',
            'longitude.max' => 'Longitude must be between -180 and 180',
            'source_id.exists' => 'The selected source does not exist',
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
            // Validate event_type against known GEDCOM event types
            $eventType = $this->event_type;
            if ($eventType) {
                $validEventTypes = array_keys(PersonService::EVENT_TYPES);
                // Allow custom event types but warn if not standard
                // For now, we allow any event type string
            }

            // If latitude is provided, longitude should also be provided
            if ($this->has('latitude') && !$this->has('longitude')) {
                $validator->errors()->add(
                    'longitude',
                    'Longitude is required when latitude is provided'
                );
            }
            if ($this->has('longitude') && !$this->has('latitude')) {
                $validator->errors()->add(
                    'latitude',
                    'Latitude is required when longitude is provided'
                );
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
