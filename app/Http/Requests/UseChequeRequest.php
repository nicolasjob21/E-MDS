<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UseChequeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The number the client believes is next. The service re-validates this against the
            // real next-available row under a lock, so this is a confirmation, not the source of truth.
            'cheque_number' => ['required', 'integer', 'min:1'],

            // Details recorded against the cheque at the moment it is used.
            'payee_name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'cheque_date' => ['required', 'date'],
        ];
    }
}
