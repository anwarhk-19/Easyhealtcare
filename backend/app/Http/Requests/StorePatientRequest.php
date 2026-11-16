<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission checked by route middleware
    }

    public function rules(): array
    {
        $updating = $this->isMethod('PATCH') || $this->isMethod('PUT');
        $req = $updating ? 'sometimes' : 'required';

        return [
            'first_name' => [$req, 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'gender' => [$req, Rule::in(['male', 'female', 'other', 'unknown'])],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'age_years' => ['nullable', 'integer', 'min:0', 'max:120'],
            'mobile' => ['nullable', 'string', 'regex:/^\+?[0-9 \-]{10,15}$/'],
            'alt_mobile' => ['nullable', 'string', 'regex:/^\+?[0-9 \-]{10,15}$/'],
            'email' => ['nullable', 'email'],
            'blood_group' => ['nullable', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'address' => ['nullable', 'array'],
            'city' => ['nullable', 'string', 'max:80'],
            'pincode' => ['nullable', 'string', 'regex:/^[0-9]{6}$/'],
            'abha_number' => ['nullable', 'string', 'regex:/^[0-9\-]{14,17}$/'],
            'emergency_contact' => ['nullable', 'array'],
            'emergency_contact.name' => ['nullable', 'string', 'max:120'],
            'emergency_contact.phone' => ['nullable', 'string', 'max:20'],
            'emergency_contact.relation' => ['nullable', 'string', 'max:40'],
            'guardian_patient_id' => ['nullable', 'string'],
            'preferred_language' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'consents' => ['nullable', 'array'],
            'consents.*' => [Rule::in(['data_processing', 'whatsapp', 'sms', 'email', 'clinical_photos', 'ai_processing'])],
            'allow_duplicate' => ['boolean'],
        ];
    }
}
