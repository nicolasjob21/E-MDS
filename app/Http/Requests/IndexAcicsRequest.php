<?php

namespace App\Http\Requests;

use App\Models\Acic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The ACIC table: `search` (the ACIC number, or the cheque / LDDAP / check / DV number of
 * anything on it), `status` (the status the table shows — Acic::DISPLAY_STATUSES — or all),
 * `category` (what it carries). They combine, and only narrow what the viewer may see (a
 * teller: only ACICs forwarded to the tellers).
 */
class IndexAcicsRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['all', ...Acic::DISPLAY_STATUSES])],
            'category' => ['nullable', 'string', Rule::in(['all', 'cheques', 'lddaps'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['status.in' => 'Choose a status from the list.'];
    }

    /** The trimmed search term, or null when nothing was searched for. */
    public function searchTerm(): ?string
    {
        $term = trim((string) $this->validated('search', ''));

        return $term === '' ? null : $term;
    }

    /** One of Acic::DISPLAY_STATUSES, or null for all. */
    public function status(): ?string
    {
        $status = (string) $this->validated('status', 'all');

        return $status === 'all' || $status === '' ? null : $status;
    }
}
