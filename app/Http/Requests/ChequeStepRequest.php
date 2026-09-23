<?php

namespace App\Http\Requests;

use App\Enums\ChequeStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What every step of the cheque flow has in common: it is the admin's to take, and it may
 * carry the status the caller's page was showing.
 *
 * That `expected_status` is how a stale page is caught. The service compares it with what the
 * row actually says and refuses the step if they differ, rather than quietly applying a move
 * the user never saw the grounds for.
 */
abstract class ChequeStepRequest extends FormRequest
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
        return array_merge([
            'expected_status' => ['nullable', 'string', Rule::enum(ChequeStatus::class)],
        ], $this->stepRules());
    }

    /**
     * The step's own fields.
     *
     * @return array<string, mixed>
     */
    abstract protected function stepRules(): array;

    /** The status the page was showing, if it sent one. */
    public function expectedStatus(): ?string
    {
        $expected = $this->validated('expected_status');

        return $expected === null ? null : (string) $expected;
    }
}
