<?php

namespace App\Http\Requests;

use App\Enums\ChequeStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The preparer's steps — Print Draft, Final Print, and editing the details. Anyone who may use a
 * cheque (staff, admin, Super Admin) may prepare one. `expected_status` catches a stale page.
 */
class ChequePrepareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [UserRole::SuperAdmin, UserRole::Admin, UserRole::Staff], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expected_status' => ['nullable', 'string', Rule::enum(ChequeStatus::class)],
        ];
    }

    public function expectedStatus(): ?string
    {
        $expected = $this->validated('expected_status');

        return $expected === null ? null : (string) $expected;
    }
}
