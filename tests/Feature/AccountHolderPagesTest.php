<?php

namespace Tests\Feature;

use App\Models\ChequeLog;
use App\Models\Creditor;
use App\Models\PcgPersonnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

/**
 * The Creditors and PCG Personnel pages' endpoints: the list, Add, and Batch Upload — which is
 * all or nothing, reports problems by row number, and never loses an account number's leading
 * zeros, from CSV or Excel.
 */
class AccountHolderPagesTest extends TestCase
{
    use RefreshDatabase;

    public static function lists(): array
    {
        return [
            'creditors' => ['/api/v1/creditors', Creditor::class],
            'pcg personnel' => ['/api/v1/pcg-personnel', PcgPersonnel::class],
        ];
    }

    #[DataProvider('lists')]
    public function test_add_stamps_date_created_and_added_by(string $url, string $model): void
    {
        $staff = User::factory()->create(['name' => 'Ana Santos']);
        Sanctum::actingAs($staff);

        $this->postJson($url, ['records' => [[
            'name' => '  Juan dela Cruz ',
            'account_no' => '0012345678',
            'unit' => 'CG-8 Comptrollership',
            'created_by' => 999,
            'created_at' => '2000-01-01',
        ]]])
            ->assertCreated()
            ->assertJsonPath('data.0.name', 'Juan dela Cruz')
            ->assertJsonPath('data.0.account_no', '0012345678')
            ->assertJsonPath('data.0.added_by.name', 'Ana Santos');

        $record = $model::sole();
        $this->assertSame($staff->id, $record->created_by);
        $this->assertTrue($record->created_at->isToday());
        $this->assertSame(1, ChequeLog::where('user_id', $staff->id)->count());
    }

    #[DataProvider('lists')]
    public function test_add_requires_name_and_account_no_but_not_unit(string $url): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson($url, ['records' => [['name' => '', 'account_no' => ' ']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['records.0.name', 'records.0.account_no'])
            ->assertJsonMissingValidationErrors(['records.0.unit']);

        $this->postJson($url, ['records' => [['name' => 'Juan', 'account_no' => '1']]])->assertCreated();
    }

    #[DataProvider('lists')]
    public function test_add_multiple_saves_every_entry_with_one_audit_entry(string $url, string $model): void
    {
        $staff = User::factory()->create();
        Sanctum::actingAs($staff);

        $this->postJson($url, ['records' => [
            ['name' => 'Juan dela Cruz', 'account_no' => '001', 'unit' => 'CG-8 Comptrollership'],
            ['name' => 'Ana Santos', 'account_no' => '0002', 'unit' => ''],
            ['name' => 'Pedro Reyes', 'account_no' => '00003'],
        ]])
            ->assertCreated()
            ->assertJsonCount(3, 'data');

        $this->assertSame(['001', '0002', '00003'], $model::orderBy('id')->pluck('account_no')->all());
        $this->assertNull($model::where('name', 'Ana Santos')->value('unit'));
        $this->assertSame(3, $model::where('created_by', $staff->id)->count());
        $this->assertSame(1, ChequeLog::where('user_id', $staff->id)->count());
    }

    #[DataProvider('lists')]
    public function test_add_multiple_saves_nothing_if_any_entry_is_wrong(string $url, string $model): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson($url, ['records' => [
            ['name' => 'Juan dela Cruz', 'account_no' => '001'],
            ['name' => 'Ana Santos', 'account_no' => ''],
            ['name' => '', 'account_no' => '003'],
        ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['records.1.account_no', 'records.2.name'])
            ->assertJsonMissingValidationErrors(['records.0.name', 'records.0.account_no']);

        $this->assertSame(0, $model::count());
    }

    #[DataProvider('lists')]
    public function test_add_takes_at_least_one_and_at_most_a_hundred(string $url): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson($url, ['records' => []])->assertJsonValidationErrors(['records']);
        $this->postJson($url, ['records' => array_fill(0, 101, ['name' => 'A', 'account_no' => '1'])])
            ->assertJsonValidationErrors(['records']);
    }

    #[DataProvider('lists')]
    public function test_lists_newest_first_with_all_five_columns_and_searches(string $url, string $model): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin One']);
        Sanctum::actingAs($admin);
        $model::create(['name' => 'Alpha', 'account_no' => '001', 'unit' => 'CG-8 Comptrollership']);
        $model::create(['name' => 'Bravo', 'account_no' => '002', 'unit' => null]);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Bravo')
            ->assertJsonPath('data.1.unit', 'CG-8 Comptrollership')
            ->assertJsonPath('data.1.added_by.name', 'Admin One')
            ->assertJsonStructure(['data' => [['name', 'account_no', 'unit', 'created_at', 'added_by']], 'meta' => ['total']]);

        $this->getJson("{$url}?search=comptroller")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("{$url}?search=002")->assertOk()->assertJsonPath('data.0.name', 'Bravo');
    }

    #[DataProvider('lists')]
    public function test_tellers_cannot_see_or_add(string $url): void
    {
        Sanctum::actingAs(User::factory()->teller()->create());

        $this->getJson($url)->assertForbidden();
        $this->postJson($url, ['records' => [['name' => 'Juan', 'account_no' => '1']]])->assertForbidden();
        $this->post("{$url}/batch-upload", ['file' => $this->csv("Name,Account No.,Unit\nJuan,1,\n")], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    #[DataProvider('lists')]
    public function test_csv_upload_saves_every_row_as_text(string $url, string $model): void
    {
        $staff = User::factory()->create();
        Sanctum::actingAs($staff);

        $csv = "\xEF\xBB\xBFName,Account No.,Unit\n"
            ."Juan dela Cruz,0012345678,CG-8 Comptrollership\n"
            ."\"Santos, Ana\",000789,\n"
            .",,\n";

        $this->upload($url, $this->csv($csv))->assertCreated()->assertJsonPath('count', 2);

        $this->assertSame(['0012345678', '000789'], $model::orderBy('id')->pluck('account_no')->all());
        $ana = $model::where('name', 'Santos, Ana')->sole();
        $this->assertNull($ana->unit);
        $this->assertSame($staff->id, $ana->created_by);
        $this->assertNotNull($ana->created_at);
    }

    #[DataProvider('lists')]
    public function test_any_bad_row_saves_nothing_and_lists_errors_by_row_number(string $url, string $model): void
    {
        Sanctum::actingAs(User::factory()->create());

        $csv = "Name,Account No.,Unit\n"
            ."Juan dela Cruz,0012345678,CG-8 Comptrollership\n"   // row 2 — fine
            .",000789,CG-8 Comptrollership\n"                      // row 3 — no name
            ."Ana Santos,,\n"                         // row 4 — no account
            ."\n"                                     // row 5 — blank, skipped
            ."Pedro Reyes,1.23457E+11,\n";            // row 6 — mangled by Excel

        $response = $this->upload($url, $this->csv($csv))->assertUnprocessable();

        $errors = $response->json('errors');
        $this->assertSame(['rows.3', 'rows.4', 'rows.6'], array_keys($errors));
        $this->assertStringStartsWith('Row 3: ', $errors['rows.3'][0]);
        $this->assertStringContainsString('Name', $errors['rows.3'][0]);
        $this->assertStringContainsString('Account No.', $errors['rows.4'][0]);
        $this->assertStringContainsString('scientific notation', $errors['rows.6'][0]);
        $this->assertSame(0, $model::count());
    }

    #[DataProvider('lists')]
    public function test_add_takes_units_only_from_the_list(string $url, string $model): void
    {
        Sanctum::actingAs(User::factory()->create());

        // Not on the list, and not quite the list's spelling — as an edited page might send.
        $this->postJson($url, ['records' => [
            ['name' => 'Juan', 'account_no' => '1', 'unit' => 'Finance'],
            ['name' => 'Ana', 'account_no' => '2', 'unit' => 'cg-8 comptrollership'],
        ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['records.0.unit', 'records.1.unit'])
            ->assertJsonPath('errors', fn ($errors) => $errors['records.0.unit'][0] === 'Choose a unit from the list.');
        $this->assertSame(0, $model::count());

        $this->postJson($url, ['records' => [
            ['name' => 'Juan', 'account_no' => '1', 'unit' => 'Coast Guard Weapons, Communications, Electronics and Information Systems Command'],
        ]])->assertCreated();
    }

    #[DataProvider('lists')]
    public function test_the_list_filters_by_unit(string $url, string $model): void
    {
        Sanctum::actingAs(User::factory()->create());
        $model::create(['name' => 'Alpha', 'account_no' => '001', 'unit' => 'CG-8 Comptrollership']);
        $model::create(['name' => 'Bravo', 'account_no' => '002', 'unit' => 'CG-4 Logistics']);
        $model::create(['name' => 'Charlie', 'account_no' => '003', 'unit' => null]);

        $this->getJson("{$url}?unit=".urlencode('CG-4 Logistics'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Bravo');
        $this->getJson("{$url}?unit=Logistics")->assertUnprocessable()->assertJsonValidationErrors(['unit']);
    }

    #[DataProvider('lists')]
    public function test_upload_matches_units_loosely_and_saves_the_official_spelling(string $url, string $model): void
    {
        Sanctum::actingAs(User::factory()->create());

        $csv = "Name,Account No.,Unit\n"
            ."Juan,001,  cg-8   COMPTROLLERSHIP \n"
            // A unit with commas, quoted, stays one value.
            ."Ana,002,\"Coast Guard Weapons,  Communications, Electronics and Information Systems Command\"\n"
            ."Pedro,003,\n";

        $this->upload($url, $this->csv($csv))->assertCreated()->assertJsonPath('count', 3);

        $this->assertSame(
            ['CG-8 Comptrollership', 'Coast Guard Weapons, Communications, Electronics and Information Systems Command', null],
            $model::orderBy('id')->pluck('unit')->all(),
        );
    }

    #[DataProvider('lists')]
    public function test_an_unknown_unit_is_a_row_error_and_saves_nothing(string $url, string $model): void
    {
        Sanctum::actingAs(User::factory()->create());

        $csv = "Name,Account No.,Unit\n"
            ."Juan,001,CG-8 Comptrollership\n"
            .",002,CG8 Comptroller\n";

        $errors = $this->upload($url, $this->csv($csv))->assertUnprocessable()->json('errors');

        $this->assertSame(['rows.3'], array_keys($errors));
        $this->assertContains("Row 3: unknown unit 'CG8 Comptroller'", $errors['rows.3']);
        $this->assertStringContainsString('Name', $errors['rows.3'][0]);
        $this->assertSame(0, $model::count());
    }

    #[DataProvider('lists')]
    public function test_header_row_must_name_the_columns(string $url): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->upload($url, $this->csv("Juan,0012345678,CG-8 Comptrollership\n"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->upload($url, $this->csv("Name,Account No.,Unit\n"))
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'The file has no rows below the header.');
    }

    #[DataProvider('lists')]
    public function test_rejects_other_file_types(string $url): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->upload($url, UploadedFile::fake()->createWithContent('list.txt', "Name\n"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    #[DataProvider('lists')]
    public function test_xlsx_upload_keeps_leading_zeros(string $url, string $model): void
    {
        Sanctum::actingAs(User::factory()->create());

        $file = $this->xlsx([
            // header, via shared strings
            ['A1' => ['s', 0], 'B1' => ['s', 1], 'C1' => ['s', 2]],
            // a text cell holding the account number
            ['A2' => ['s', 3], 'B2' => ['s', 4], 'C2' => ['s', 5]],
            // a number cell styled "0000000000", as Excel does to show the zeros
            ['A3' => ['inlineStr', 'Ana Santos'], 'B3' => ['n', '12345678', 1]],
            // a long number Excel wrote in scientific notation
            ['A4' => ['inlineStr', 'Pedro Reyes'], 'B4' => ['n', '1.2345678901E+11']],
        ], ['Name', 'Account No.', 'Unit', 'Juan dela Cruz', '0012345678', 'CG-8 Comptrollership']);

        $this->upload($url, $file)->assertCreated()->assertJsonPath('count', 3);

        $this->assertSame(
            ['0012345678', '0012345678', '123456789010'],
            $model::orderBy('id')->pluck('account_no')->all(),
        );
        $this->assertSame('CG-8 Comptrollership', $model::where('name', 'Juan dela Cruz')->value('unit'));
    }

    #[DataProvider('lists')]
    public function test_xlsx_errors_use_the_sheet_row_numbers(string $url, string $model): void
    {
        Sanctum::actingAs(User::factory()->create());

        $file = $this->xlsx([
            ['A1' => ['inlineStr', 'Name'], 'B1' => ['inlineStr', 'Account No.'], 'C1' => ['inlineStr', 'Unit']],
            ['A2' => ['inlineStr', 'Juan'], 'B2' => ['inlineStr', '001']],
            ['A7' => ['inlineStr', 'Ana']],
        ]);

        $this->upload($url, $file)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rows.7']);
        $this->assertSame(0, $model::count());
    }

    public function test_a_corrupt_xlsx_is_refused_politely(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->upload('/api/v1/creditors', UploadedFile::fake()->createWithContent('list.xlsx', 'not a zip'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'The file is not a valid Excel (.xlsx) workbook.');
    }

    private function upload(string $url, UploadedFile $file): TestResponse
    {
        return $this->post("{$url}/batch-upload", ['file' => $file], ['Accept' => 'application/json']);
    }

    private function csv(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('list.csv', $contents);
    }

    /**
     * A minimal workbook: one sheet, the given cells, a shared-string table, and a styles part
     * whose cell style 1 is the zero-padded number format "0000000000".
     *
     * @param  list<array<string, array{0: string, 1: int|string, 2?: int}>>  $rows  ref => [type, value, style?]
     * @param  list<string>  $shared
     */
    private function xlsx(array $rows, array $shared = []): UploadedFile
    {
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $esc = fn ($s) => htmlspecialchars((string) $s, ENT_XML1);

        $sheetRows = '';
        foreach ($rows as $cells) {
            $rowNo = (int) preg_replace('/\D/', '', array_key_first($cells));
            $sheetRows .= "<row r=\"{$rowNo}\">";
            foreach ($cells as $ref => $cell) {
                [$type, $value] = $cell;
                $style = isset($cell[2]) ? " s=\"{$cell[2]}\"" : '';
                $sheetRows .= $type === 'inlineStr'
                    ? "<c r=\"{$ref}\" t=\"inlineStr\"{$style}><is><t>{$esc($value)}</t></is></c>"
                    : "<c r=\"{$ref}\" t=\"{$type}\"{$style}><v>{$esc($value)}</v></c>";
            }
            $sheetRows .= '</row>';
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', "<workbook {$ns} xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\"><sheets><sheet name=\"List\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>");
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/list.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/list.xml', "<worksheet {$ns}><sheetData>{$sheetRows}</sheetData></worksheet>");
        $zip->addFromString('xl/sharedStrings.xml', "<sst {$ns}>".implode('', array_map(fn ($s) => "<si><t>{$esc($s)}</t></si>", $shared)).'</sst>');
        $zip->addFromString('xl/styles.xml', "<styleSheet {$ns}><numFmts count=\"1\"><numFmt numFmtId=\"164\" formatCode=\"0000000000\"/></numFmts><cellXfs count=\"2\"><xf numFmtId=\"0\"/><xf numFmtId=\"164\"/></cellXfs></styleSheet>");
        $zip->close();

        return new UploadedFile($path, 'list.xlsx', null, null, true);
    }
}
