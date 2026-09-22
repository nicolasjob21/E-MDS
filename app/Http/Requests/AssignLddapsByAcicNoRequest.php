<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Assign LDDAP to ACIC" by ACIC number: the number the user typed, the records ticked (in the
 * order shown), and the check numbers they were shown as about to receive.
 */
class AssignLddapsByAcicNoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [UserRole::Admin, UserRole::Staff], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'acic_no' => ['required', 'integer', 'min:1'],
            'lddap_ids' => ['required', 'array', 'min:1', 'max:500'],
            'lddap_ids.*' => ['integer', 'exists:lddaps,id'],
            'expected_check_nos' => ['nullable', 'array', 'max:500'],
            'expected_check_nos.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'acic_no.required' => 'Enter the ACIC number.',
            'acic_no.integer' => 'The ACIC number must be a whole number.',
            'lddap_ids.required' => 'Select at least one LDDAP record.',
            'lddap_ids.min' => 'Select at least one LDDAP record.',
        ];
    }
}
