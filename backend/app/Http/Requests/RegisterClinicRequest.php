<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterClinicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'clinic_name' => ['required', 'string', 'max:160'],
            'owner_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:160', 'unique:users,email'],
            'mobile' => ['nullable', 'string', 'regex:/^\+?[0-9 \-]{10,15}$/'],
            'password' => ['required', Password::min(10)->letters()->numbers()],
            'plan_code' => ['nullable', 'exists:plans,code'],
            'speciality_codes' => ['nullable', 'array'],
            'speciality_codes.*' => ['exists:specialities,code'],
            'owner_is_doctor' => ['boolean'],
            'registration_number' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:80'],
            'branch_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
