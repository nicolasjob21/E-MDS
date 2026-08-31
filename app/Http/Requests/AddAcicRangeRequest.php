<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Register a block of ACIC numbers. Admin only, as with the cheque and LDDAP check series.
 */
class AddAcicRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_at' => ['required', 'integer', 'min:1'],
            'end_at' => ['required', 'integer', 'min:1', 'gte:start_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_at.required' => 'Enter the first ACIC number in the block.',
            'end_at.required' => 'Enter the last ACIC number in the block.',
            'end_at.gte' => 'The last ACIC number must be the same as or higher than the first.',
        ];
    }
}
