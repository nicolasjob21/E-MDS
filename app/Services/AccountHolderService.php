<?php

namespace App\Services;

use App\Enums\ChequeAction;
use App\Models\AccountHolder;
use App\Models\Creditor;
use App\Models\PcgPersonnel;
use App\Models\User;
use App\Support\PcgUnits;
use App\Support\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Adding to the creditor and PCG personnel lists, one at a time or by batch upload.
 *
 * A batch is all or nothing: every row is checked first, and if any row is wrong nothing is
 * saved and each problem is reported against its row number in the file.
 */
class AccountHolderService
{
    /** More rows than this is almost certainly the wrong file. */
    public const MAX_ROWS = 5000;

    /** Accepted spellings of each header, compared lower-case with punctuation and spaces removed. */
    private const HEADERS = [
        'name' => ['name', 'creditorname', 'personnelname', 'fullname'],
        'account_no' => ['accountno', 'accountnumber', 'acctno', 'accountnum'],
        'unit' => ['unit'],
    ];

    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly SpreadsheetReader $reader,
    ) {}

    /**
     * Save one or more entries from the Add form, all or none.
     *
     * @param  class-string<AccountHolder>  $model
     * @param  list<array{name: string, account_no: string, unit: string|null}>  $entries
     * @return Collection<int, AccountHolder>
     */
    public function add(User $user, string $model, array $entries): Collection
    {
        return DB::transaction(function () use ($user, $model, $entries) {
            $records = collect($entries)->map(function (array $attributes) use ($user, $model) {
                $record = new $model;
                $record->fill($attributes);
                $record->created_by = $user->id;
                $record->save();

                return $record;
            });

            $label = $model::label();
            $this->logger->log(
                $user,
                $this->action($model, batch: false),
                null,
                $records->count() === 1
                    ? "Added {$label} '{$records[0]->name}' (account {$records[0]->account_no})."
                    : "Added {$records->count()} {$label} record(s): "
                        .$records->map(fn ($r) => "'{$r->name}' ({$r->account_no})")->implode(', ').'.',
            );

            return $records;
        });
    }

    /**
     * Save every row of the uploaded file, or none of them.
     *
     * @param  class-string<AccountHolder>  $model
     * @return int the number of records saved
     *
     * @throws ValidationException listing each problem by row number
     */
    public function upload(User $user, string $model, UploadedFile $file): int
    {
        try {
            $rows = $this->reader->read($file->getRealPath(), $file->getClientOriginalExtension());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $header = array_shift($rows);
        if ($header === null) {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        $columns = $this->columns($header['cells']);
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The file has no rows below the header.']);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => 'The file has '.count($rows).' rows; upload at most '.self::MAX_ROWS.' at a time.',
            ]);
        }

        $records = [];
        $errors = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $field => $index) {
                $value = trim($row['cells'][$index] ?? '');
                $values[$field] = $value === '' ? null : $value;
            }
            $values['unit'] ??= null;

            // A typed unit matches the list ignoring capitalization and extra spaces, and is
            // saved as the list spells it.
            $problems = [];
            if ($values['unit'] !== null) {
                $official = PcgUnits::canonical($values['unit']);
                if ($official === null) {
                    $problems[] = "unknown unit '{$values['unit']}'";
                }
                $values['unit'] = $official ?? $values['unit'];
            }

            $problems = [...$this->rowErrors($values), ...$problems];
            if ($problems !== []) {
                $errors["rows.{$row['row']}"] = array_map(fn ($p) => "Row {$row['row']}: {$p}", $problems);

                continue;
            }
            $records[] = $values;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($user, $model, $records, $file) {
            $now = now();
            foreach (array_chunk($records, 500) as $chunk) {
                $model::query()->insert(array_map(fn ($r) => $r + [
                    'created_by' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            $this->logger->log(
                $user,
                $this->action($model, batch: true),
                null,
                'Batch uploaded '.count($records)." {$model::label()} record(s) from '{$file->getClientOriginalName()}'.",
            );
        });

        return count($records);
    }

    /**
     * Where each field sits, read from the header row.
     *
     * @param  list<string>  $header
     * @return array<string, int>
     */
    private function columns(array $header): array
    {
        $columns = [];
        foreach ($header as $index => $title) {
            $key = preg_replace('/[^a-z]/', '', strtolower($title));
            foreach (self::HEADERS as $field => $spellings) {
                if (! isset($columns[$field]) && in_array($key, $spellings, true)) {
                    $columns[$field] = $index;
                }
            }
        }

        $missing = array_diff_key(['name' => 'Name', 'account_no' => 'Account No.'], $columns);
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => 'The first row must be the header — Name, Account No., Unit. Missing: '
                    .implode(', ', $missing).'.',
            ]);
        }

        return $columns;
    }

    /**
     * @param  array<string, string|null>  $values
     * @return list<string>
     */
    private function rowErrors(array $values): array
    {
        $validator = Validator::make($values, [
            'name' => ['required', 'string', 'max:255'],
            'account_no' => ['required', 'string', 'max:255', 'not_regex:/^\d+(\.\d+)?E\+?\d+$/i'],
            'unit' => ['nullable', 'string'],
        ], [
            'account_no.not_regex' => 'Account No. ":input" was turned into scientific notation by the spreadsheet. Format the column as Text, re-enter the numbers and upload again.',
        ], [
            'name' => 'Name',
            'account_no' => 'Account No.',
            'unit' => 'Unit',
        ]);

        return array_values($validator->errors()->all());
    }

    /** @param class-string<AccountHolder> $model */
    private function action(string $model, bool $batch): ChequeAction
    {
        return match ($model) {
            Creditor::class => $batch ? ChequeAction::UploadedCreditors : ChequeAction::AddedCreditor,
            PcgPersonnel::class => $batch ? ChequeAction::UploadedPcgPersonnel : ChequeAction::AddedPcgPersonnel,
        };
    }
}
