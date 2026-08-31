<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Swap one record on an ACIC for another. Admin and staff both manage ACIC membership, so both
 * may re-assign; the service re-checks eligibility under a lock.
 */
class ReassignAcicRequest extends FormRequest
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
            'type' => ['required', Rule::in(['cheque', 'lddap'])],
            // The record coming off the ACIC, and the one going on in its place.
            'release_id' => ['required', 'integer', 'min:1'],
            'assign_id' => ['required', 'integer', 'min:1', 'different:release_id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'Choose whether a cheque or an LDDAP is being re-assigned.',
            'release_id.required' => 'Choose the record to take off this ACIC.',
            'assign_id.required' => 'Choose the record to put on in its place.',
            'assign_id.different' => 'Choose a different record to put on in its place.',
        ];
    }
}
