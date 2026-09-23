<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Branch B, step 1 — the admin sends a whole ACIC to the tellers for deposit.
 *
 * Distinct from `ForwardAcicRequest`, which forwards an approved ACIC on to a named person:
 * this one puts it in front of every teller, for the first of them to claim.
 */
class ForwardAcicToTellerRequest extends FormRequest
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
            'date_forwarded' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
