<?php

namespace App\Http\Requests;

use App\Enums\ChequeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewChequeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already behind the `admin` middleware.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The outcome the admin picked: approved | complies | disapproved.
            'status' => ['required', Rule::in(array_column(ChequeStatus::reviewOutcomes(), 'value'))],

            // A straight approval needs no explanation; anything else must say why.
            'review_note' => [
                Rule::requiredIf(fn () => $this->input('status') !== ChequeStatus::Approved->value),
                'nullable',
                'string',
                'min:5',
                'max:1000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'review_note.required' => 'Give a reason or note for this outcome.',
        ];
    }

    public function outcome(): ChequeStatus
    {
        return ChequeStatus::from($this->string('status')->toString());
    }
}
