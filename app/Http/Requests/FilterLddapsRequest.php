<?php

namespace App\Http\Requests;

use App\Enums\LddapStatus;
use App\Enums\NatureOfPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The LDDAP table's filter bar, as query parameters so a filtered view can be bookmarked:
 * `search`, `status`, `nature`, `page`, `per_page`. Every filter is optional; "all" and an
 * empty value both mean "don't filter on this".
 */
class FilterLddapsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['all', ...array_column(LddapStatus::cases(), 'value')])],
            'nature' => ['nullable', 'string', Rule::in(['all', ...array_column(NatureOfPayment::cases(), 'value')])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Choose a status from the list.',
            'nature.in' => 'Choose a nature of payment from the list.',
        ];
    }

    /** The search text, trimmed; empty when there is none. */
    public function search(): string
    {
        return trim((string) $this->validated('search', ''));
    }

    /** The status to filter on, or null for all. */
    public function status(): ?LddapStatus
    {
        return LddapStatus::tryFrom((string) $this->validated('status', 'all'));
    }

    /** The nature of payment to filter on, or null for all. */
    public function nature(): ?NatureOfPayment
    {
        return NatureOfPayment::tryFrom((string) $this->validated('nature', 'all'));
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 50);
    }
}
