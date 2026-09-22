<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The full set of details an LDDAP-ADA is registered with, plus the two registers they draw on.
 *
 * `units` and `payees` are reference lists: an LDDAP names its unit and is paid to a registered
 * payee, whose name and account number are copied onto the record at registration so the
 * record stands on its own even if the payee is later edited. `obj_no` already holds the UACS
 * object code (it is what prints as OBJ CODE on the ACIC), so it is reused, not duplicated;
 * `check_date` is the date of issue, now entered rather than stamped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('payees', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('account_no', 100);
            $table->timestamps();

            $table->unique(['name', 'account_no']);
            $table->index('name');
        });

        Schema::table('lddaps', function (Blueprint $table) {
            // Transaction references.
            $table->string('nca_no', 100)->nullable()->after('lddap_no');
            $table->string('orb_no', 100)->nullable()->after('nca_no');
            $table->string('dv_no', 100)->nullable()->after('orb_no');

            $table->string('nature_of_payment', 50)->nullable()->after('dv_no');

            $table->foreignId('unit_id')->nullable()->after('nature_of_payment')
                ->constrained('units')->nullOnDelete();

            // The registered payee, and the account on file when the LDDAP was registered.
            $table->foreignId('payee_id')->nullable()->after('payee_name')
                ->constrained('payees')->nullOnDelete();
            $table->string('payee_account_no', 100)->nullable()->after('payee_id');
        });
    }

    public function down(): void
    {
        Schema::table('lddaps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payee_id');
            $table->dropConstrainedForeignId('unit_id');
            $table->dropColumn(['nca_no', 'orb_no', 'dv_no', 'nature_of_payment', 'payee_account_no']);
        });

        Schema::dropIfExists('payees');
        Schema::dropIfExists('units');
    }
};
