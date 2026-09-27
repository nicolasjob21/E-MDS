<?php

namespace Tests\Feature;

use App\Models\Creditor;
use App\Models\PcgPersonnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The creditor and PCG personnel reference lists: account numbers keep their leading zeros, and
 * the Date Created / Added By stamps are set on creation and never change.
 */
class CreditorAndPcgPersonnelTest extends TestCase
{
    use RefreshDatabase;

    public static function models(): array
    {
        return [
            'creditors' => [Creditor::class],
            'pcg personnel' => [PcgPersonnel::class],
        ];
    }

    /** @param class-string<Creditor|PcgPersonnel> $model */
    #[DataProvider('models')]
    public function test_stamps_the_adding_user_and_keeps_leading_zeros(string $model): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $record = $model::create(['name' => 'Juan dela Cruz', 'account_no' => '0012345678', 'unit' => 'CG-8 Comptrollership']);

        $fresh = $record->fresh();
        $this->assertSame('0012345678', $fresh->account_no);
        $this->assertSame($user->id, $fresh->created_by);
        $this->assertNotNull($fresh->created_at);
        $this->assertTrue($fresh->creator->is($user));
    }

    /** @param class-string<Creditor|PcgPersonnel> $model */
    #[DataProvider('models')]
    public function test_unit_is_optional(string $model): void
    {
        $this->actingAs(User::factory()->create());

        $record = $model::create(['name' => 'Juan dela Cruz', 'account_no' => '001']);

        $this->assertNull($record->fresh()->unit);
    }

    /** @param class-string<Creditor|PcgPersonnel> $model */
    #[DataProvider('models')]
    public function test_date_created_and_added_by_cannot_be_changed(string $model): void
    {
        $adder = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($adder);

        $record = $model::create([
            'name' => 'Juan dela Cruz',
            'account_no' => '001',
            'created_by' => $other->id,
            'created_at' => '2000-01-01 00:00:00',
        ]);
        $this->assertSame($adder->id, $record->fresh()->created_by);
        $this->assertNotEquals('2000-01-01', $record->fresh()->created_at->toDateString());

        $stampedAt = $record->fresh()->created_at;
        $record->created_by = $other->id;
        $record->created_at = Carbon::parse('2000-01-01');
        $record->name = 'Maria Clara';
        $record->save();

        $fresh = $record->fresh();
        $this->assertSame('Maria Clara', $fresh->name);
        $this->assertSame($adder->id, $fresh->created_by);
        $this->assertTrue($fresh->created_at->equalTo($stampedAt));
    }

    /** @param class-string<Creditor|PcgPersonnel> $model */
    #[DataProvider('models')]
    public function test_record_survives_its_adder_being_deleted(string $model): void
    {
        $adder = User::factory()->create();
        $this->actingAs($adder);
        $record = $model::create(['name' => 'Juan dela Cruz', 'account_no' => '001']);

        $adder->delete();

        $this->assertNull($record->fresh()->created_by);
    }
}
