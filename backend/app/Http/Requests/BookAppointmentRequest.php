<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'string'],
            'doctor_profile_id' => ['required', 'string'],
            'branch_id' => ['required', 'string'],
            'scheduled_start' => ['required', 'date'],
            'service_id' => ['nullable', 'string'],
            'speciality_id' => ['nullable', 'exists:specialities,id'],
            'type' => ['nullable', Rule::in(['scheduled', 'follow_up', 'online'])],
            'source' => ['nullable', Rule::in(['reception', 'phone', 'patient_app', 'public_page'])],
            'notes' => ['nullable', 'string', 'max:500'],
            'override_schedule' => ['boolean'],
        ];
    }
}
