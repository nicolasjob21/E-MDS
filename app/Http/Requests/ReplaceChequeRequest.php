<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Issuing a replacement for a stale cheque. Admin/finance only — in this system that is the
 * admin role, which holds the finance duties.
 */
class ReplaceChequeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The replacement's own date; today when the caller leaves it out.
            'cheque_date' => ['nullable', 'date'],
        ];
    }
}
