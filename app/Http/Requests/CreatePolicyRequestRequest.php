<?php

namespace App\Http\Requests;

use App\Enums\Destination;
use App\Enums\PlanCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePolicyRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_request_id' => ['required', 'string', 'max:100'],
            'agent_id' => ['required', 'string', 'max:100'],
            'customer_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'destination' => ['required', 'string', Rule::in(array_column(Destination::cases(), 'value'))],
            'trip_start' => ['required', 'date', 'after_or_equal:today'],
            'trip_end' => ['required', 'date', 'after_or_equal:trip_start'],
            'plan_code' => ['required', 'string', Rule::in(array_column(PlanCode::cases(), 'value'))],
            'sum_insured' => ['required', 'integer', Rule::in([100000, 300000, 500000])],
        ];
    }

    public function messages(): array
    {
        return [
            'trip_end.after_or_equal' => 'The trip end must be a date after or equal to trip start.',
            'plan_code.in' => 'The selected plan code is invalid. Supported: BASIC, PLUS, PREMIUM.',
            'sum_insured.in' => 'The selected sum insured is invalid. Supported: 100000, 300000, 500000.',
            'destination.in' => 'The selected destination is invalid. Supported: DOMESTIC, INTERNATIONAL.',
            'date_of_birth.before' => 'The date of birth must be a date before today.',
        ];
    }
}
