<?php

namespace App\Http\Requests;

use App\Support\PcgUnits;

/**
 * The Add form: one or more entries, each only a name, account number and unit. Date Created
 * and Added By are stamped on save, so they are not accepted here. All entries are saved, or
 * none; a problem is reported against its entry as `records.N.field`.
 */
class StoreAccountHolderRequest extends AccountHolderRequest
{
    public const MAX_RECORDS = 100;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'records' => ['required', 'array', 'min:1', 'max:'.self::MAX_RECORDS],
            'records.*' => ['array'],
            'records.*.name' => ['required', 'string', 'max:255'],
            'records.*.account_no' => ['required', 'string', 'max:255'],
            'records.*.unit' => ['nullable', 'string', PcgUnits::rule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'records.*.name' => 'Name',
            'records.*.account_no' => 'Account No.',
            'records.*.unit' => 'Unit',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'records.required' => 'Enter at least one entry.',
            'records.*.unit.in' => 'Choose a unit from the list.',
            'records.max' => 'Add at most '.self::MAX_RECORDS.' entries at a time — use Batch Upload for more.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $records = $this->input('records');
        if (! is_array($records)) {
            return;
        }

        $this->merge(['records' => array_map(
            fn ($record) => is_array($record)
                ? array_map(fn ($v) => is_string($v) ? trim($v) : $v, $record)
                : $record,
            $records,
        )]);
    }

    /**
     * @return list<array{name: string, account_no: string, unit: string|null}>
     */
    public function records(): array
    {
        return array_map(fn (array $r) => [
            'name' => $r['name'],
            'account_no' => $r['account_no'],
            'unit' => ($r['unit'] ?? '') === '' ? null : $r['unit'],
        ], array_values($this->validated('records')));
    }
}
