<?php

namespace App\Http\Requests;

use App\Services\LddapService;
use Illuminate\Foundation\Http\FormRequest;

class AddLddapCheckRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is gated by the `admin` middleware.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_at' => ['required', 'integer', 'min:1', 'max:'.LddapService::MAX_CHECK_NO],
            'end_at' => ['required', 'integer', 'min:1', 'max:'.LddapService::MAX_CHECK_NO, 'gte:start_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_at.required' => 'Enter the first check number in the range.',
            'end_at.required' => 'Enter the last check number in the range.',
            'end_at.gte' => 'The last check number must be the same as or higher than the first.',
            'start_at.max' => 'That check number is too large.',
            'end_at.max' => 'That check number is too large.',
        ];
    }
}
