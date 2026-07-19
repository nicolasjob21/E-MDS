<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddChequeRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is already gated by the `admin` middleware.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'count' => ['required', 'integer', 'min:1', 'max:100000'],
            // Only used when no cheques exist yet (the very first range). Ignored afterwards.
            'start_at' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
