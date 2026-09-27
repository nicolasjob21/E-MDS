<?php

namespace App\Http\Requests;

use App\Enums\ChequeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The admin in charge checks a draft: Approve (comment optional) or Return (comment required —
 * what to change). Administrators and Super Admins.
 */
class CheckDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expected_status' => ['nullable', 'string', Rule::enum(ChequeStatus::class)],
            // Required on Return: the preparer needs to know what to change.
            'comment' => [str_ends_with($this->path(), '/return-draft') ? 'required' : 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['comment.required' => 'Say what needs to change in the draft.'];
    }

    public function expectedStatus(): ?string
    {
        $expected = $this->validated('expected_status');

        return $expected === null ? null : (string) $expected;
    }
}
