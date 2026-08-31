<?php

namespace App\Http\Requests;

use App\Enums\LddapStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewLddapRequest extends FormRequest
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
            'status' => [
                'required',
                Rule::in(array_map(fn (LddapStatus $s) => $s->value, LddapStatus::reviewOutcomes())),
            ],
            // Required when sending the record back, so the staff member knows what to fix.
            'review_note' => [
                'nullable',
                'required_if:status,'.LddapStatus::Compliance->value,
                'string',
                'max:2000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Choose a review outcome.',
            'status.in' => 'That is not a valid review outcome.',
            'review_note.required_if' => 'Say what needs to be fixed before returning this to the staff member.',
        ];
    }
}
