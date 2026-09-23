<?php

namespace App\Http\Requests;

use App\Enums\AcicTellerStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What every teller step on an ACIC has in common: it belongs to a teller (an admin may look
 * in), and it may carry the status the caller's page was showing.
 *
 * That `expected_status` is how a stale page is caught. Which teller may act on *this* ACIC is
 * the service's call — it holds the lock.
 */
abstract class AcicTellerStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [UserRole::Teller, UserRole::Admin], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'expected_status' => ['nullable', 'string', Rule::enum(AcicTellerStatus::class)],
        ], $this->stepRules());
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function stepRules(): array;

    public function expectedStatus(): ?string
    {
        $expected = $this->validated('expected_status');

        return $expected === null ? null : (string) $expected;
    }
}
