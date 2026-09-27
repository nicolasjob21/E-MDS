<?php

namespace App\Http\Requests;

use App\Enums\UserRole;

/**
 * "Use previous ACIC" on a replacement cheque: put it on the ACIC its spoiled cheque came off.
 * Taken by whoever may assign cheques to an ACIC — admin and staff.
 */
class UsePreviousAcicRequest extends ChequeStepRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [UserRole::SuperAdmin, UserRole::Admin, UserRole::Staff], true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function stepRules(): array
    {
        return [];
    }
}
