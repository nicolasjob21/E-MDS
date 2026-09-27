<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Register LDDAP Record form, reworked:
 *
 * - "ORB Number" is **OBR Number** — the column is renamed `orb_no` → `obr_no`.
 * - The payee comes from the Creditors or PCG Personnel lists, and the LDDAP keeps its own copy
 *   of the name, account number and unit — plus, new here, **`payee_type`** ("creditor" or
 *   "pcg_personnel"). Nothing links back to the source record. Existing LDDAPs keep it blank.
 * - **DV Number is unique.** Stored trimmed; the index backs the form rule and the service's
 *   own check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lddaps', function (Blueprint $table) {
            $table->renameColumn('orb_no', 'obr_no');
        });

        DB::table('lddaps')->whereNotNull('dv_no')->update(['dv_no' => DB::raw('trim(dv_no)')]);

        Schema::table('lddaps', function (Blueprint $table) {
            $table->string('payee_type', 20)->nullable()->after('payee_name');
            $table->unique('dv_no');
        });
    }

    public function down(): void
    {
        Schema::table('lddaps', function (Blueprint $table) {
            $table->dropUnique(['dv_no']);
            $table->dropColumn('payee_type');
            $table->renameColumn('obr_no', 'orb_no');
        });
    }
};
