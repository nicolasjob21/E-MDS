<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CashChequeRequest extends FormRequest
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
            'teller_name' => ['required', 'string', 'max:255'],
            'cashed_at' => ['required', 'date'],
        ];
    }
}
