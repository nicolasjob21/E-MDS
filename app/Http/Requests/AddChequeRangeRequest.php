<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AddChequeRangeRequest extends FormRequest
{
    /** A single physical cheque book; guards against a typo registering millions of rows. */
    private const MAX_BOOK_SIZE = 100000;

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
            // The first and last serial printed on the newly issued cheque book.
            'start_at' => ['required', 'integer', 'min:1'],
            'end_at' => ['required', 'integer', 'min:1', 'gte:start_at'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $count = $this->integer('end_at') - $this->integer('start_at') + 1;

            if ($count > self::MAX_BOOK_SIZE) {
                $validator->errors()->add(
                    'end_at',
                    'That range covers '.number_format($count).' cheques. Register at most '
                        .number_format(self::MAX_BOOK_SIZE).' at a time.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_at.required' => 'Enter the first serial number on the cheque book.',
            'end_at.required' => 'Enter the last serial number on the cheque book.',
            'end_at.gte' => 'The last serial number must be the same as or higher than the first.',
        ];
    }
}
