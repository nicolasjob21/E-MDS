<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectUpdateRequestRequest extends FormRequest
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
            'review_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
