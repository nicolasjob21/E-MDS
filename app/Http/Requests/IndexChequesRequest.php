<?php

namespace App\Http\Requests;

use App\Enums\ChequeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexChequesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Listing is open to any authenticated user.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // "all" or any ChequeStatus value; anything else is ignored rather than rejected.
            'status' => ['nullable', 'string', 'max:32'],
            // Matches against the cheque number or the ACIC no.
            'search' => ['nullable', 'string', 'max:100'],
            // The tab is one of the two derived views, or any status in the flow.
            'tab' => ['nullable', 'string', Rule::in(self::tabs())],
            // "expiry" puts the cheques closest to going stale first.
            'sort' => ['nullable', 'string', Rule::in(['number', 'expiry'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Every tab the cheque page may ask for: the two derived views, plus **any** status in the
     * flow.
     *
     * Derived from the enum rather than listed, so adding a status can never leave the page
     * sending a tab the server calls invalid.
     *
     * @return list<string>
     */
    public static function tabs(): array
    {
        return ['all', 'valid', 'expiring', ...array_column(ChequeStatus::cases(), 'value')];
    }

    /** The chosen tab; "all" when none or an unknown one was asked for. */
    public function tab(): string
    {
        $tab = (string) $this->input('tab', 'all');

        return in_array($tab, self::tabs(), true) ? $tab : 'all';
    }

    public function sortsByExpiry(): bool
    {
        return $this->input('sort') === 'expiry';
    }

    /** The trimmed search term, or null when nothing was searched for. */
    public function searchTerm(): ?string
    {
        $search = trim((string) $this->input('search', ''));

        return $search === '' ? null : $search;
    }
}
