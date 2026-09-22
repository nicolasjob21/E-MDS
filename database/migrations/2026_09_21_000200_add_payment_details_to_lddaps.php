<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The payment side of an LDDAP: a payee may hold several bank accounts, the gross amount is
 * broken down into withholding tax, VAT and deductions, and the record carries its dates and
 * notes.
 *
 * `amount` keeps its meaning downstream — it is what the ACIC prints and totals — so it becomes
 * the **net** payable: gross less every tax and deduction. Existing rows are backfilled with
 * `gross_amount = amount` (nothing was ever withheld from them), so they read unchanged.
 *
 * Every money column is `decimal(14, 2)`: amounts are stored as decimals, never floats.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- a payee's bank accounts, one row each --------------------------------------
        Schema::create('payee_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payee_id')->constrained('payees')->cascadeOnDelete();
            $table->string('account_no', 100);
            $table->string('bank', 100);
            $table->timestamps();

            $table->unique(['payee_id', 'account_no']);
        });

        // The single account each payee held so far moves across, so nothing is lost. The
        // depository bank is the only one on file, hence the default.
        $now = now();
        $rows = DB::table('payees')->select(['id', 'account_no'])->get()
            ->map(fn ($p) => [
                'payee_id' => $p->id,
                'account_no' => $p->account_no,
                'bank' => 'LBP',
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('payee_accounts')->insert($chunk);
        }

        Schema::table('payees', function (Blueprint $table) {
            $table->dropUnique(['name', 'account_no']);
            $table->dropColumn('account_no');
        });

        // ---- the LDDAP's payment breakdown ------------------------------------------------
        Schema::table('lddaps', function (Blueprint $table) {
            // The account the payment goes to, and its bank as of registration.
            $table->foreignId('payee_account_id')->nullable()->after('payee_id')
                ->constrained('payee_accounts')->nullOnDelete();
            $table->string('payee_bank', 100)->nullable()->after('payee_account_no');

            // The ACIC number written on the form at registration. Distinct from `acic_id`,
            // which is the ACIC the record is actually put on after approval.
            $table->string('acic_ref', 100)->nullable()->after('payee_bank');

            $table->decimal('gross_amount', 14, 2)->default(0)->after('amount');

            // Withholding tax, by rate.
            $table->decimal('wtax_1', 14, 2)->default(0)->after('gross_amount');
            $table->decimal('wtax_2', 14, 2)->default(0)->after('wtax_1');
            $table->decimal('wtax_3', 14, 2)->default(0)->after('wtax_2');
            $table->decimal('wtax_5', 14, 2)->default(0)->after('wtax_3');

            // VAT, by rate.
            $table->decimal('vat_1', 14, 2)->default(0)->after('wtax_5');
            $table->decimal('vat_2', 14, 2)->default(0)->after('vat_1');
            $table->decimal('vat_3', 14, 2)->default(0)->after('vat_2');
            $table->decimal('vat_5', 14, 2)->default(0)->after('vat_3');
            $table->decimal('vat_10', 14, 2)->default(0)->after('vat_5');
            $table->decimal('vat_12', 14, 2)->default(0)->after('vat_10');
            $table->decimal('vat_30', 14, 2)->default(0)->after('vat_12');

            // Deductions.
            $table->decimal('retention', 14, 2)->default(0)->after('vat_30');
            $table->decimal('liquidated_damages', 14, 2)->default(0)->after('retention');
            $table->decimal('advance_payment', 14, 2)->default(0)->after('liquidated_damages');

            // Dates and notes.
            $table->date('fwd_to_lbp_at')->nullable()->after('check_date');
            $table->date('date_loaded')->nullable()->after('fwd_to_lbp_at');
            $table->text('note')->nullable()->after('date_loaded');
            $table->string('remarks')->nullable()->after('note');
        });

        // Nothing was withheld from a record that predates the breakdown: gross is what it paid.
        DB::table('lddaps')->update(['gross_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('lddaps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payee_account_id');
            $table->dropColumn([
                'payee_bank', 'acic_ref', 'gross_amount',
                'wtax_1', 'wtax_2', 'wtax_3', 'wtax_5',
                'vat_1', 'vat_2', 'vat_3', 'vat_5', 'vat_10', 'vat_12', 'vat_30',
                'retention', 'liquidated_damages', 'advance_payment',
                'fwd_to_lbp_at', 'date_loaded', 'note', 'remarks',
            ]);
        });

        Schema::table('payees', function (Blueprint $table) {
            $table->string('account_no', 100)->default('')->after('name');
        });

        // Put each payee's first account back on the payee row.
        foreach (DB::table('payee_accounts')->orderBy('id')->get() as $account) {
            DB::table('payees')->where('id', $account->payee_id)->where('account_no', '')
                ->update(['account_no' => $account->account_no]);
        }

        Schema::table('payees', function (Blueprint $table) {
            $table->unique(['name', 'account_no']);
        });

        Schema::dropIfExists('payee_accounts');
    }
};
