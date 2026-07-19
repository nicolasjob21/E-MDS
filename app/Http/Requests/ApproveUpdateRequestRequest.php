<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveUpdateRequestRequest extends FormRequest
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
        // Approval simply applies the staff-proposed values; only an optional note is accepted.
        return [
            'review_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
