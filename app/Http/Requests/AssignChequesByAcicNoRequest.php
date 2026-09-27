<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Assign Cheque to ACIC" by ACIC number: the number typed — an existing open ACIC, or the next
 * one in the series — and the For Signature cheques ticked. Many cheques may share one number.
 */
class AssignChequesByAcicNoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [UserRole::SuperAdmin, UserRole::Admin, UserRole::Staff], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'acic_no' => ['required', 'integer', 'min:1'],
            'cheque_ids' => ['required', 'array', 'min:1', 'max:500'],
            'cheque_ids.*' => ['integer', 'exists:cheques,id'],
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
            'cheque_ids.required' => 'Select at least one cheque.',
            'cheque_ids.min' => 'Select at least one cheque.',
            'cheque_ids.*.exists' => 'One or more of the selected cheques no longer exists.',
        ];
    }
}
