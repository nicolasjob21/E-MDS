<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreUpdateRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only staff may request detail updates.
        return $this->user()?->role === UserRole::Staff;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The corrected values the staff member is proposing.
            'payee_name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'cheque_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
