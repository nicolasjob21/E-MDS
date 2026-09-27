<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The teller's Forward to Payee records who received each cheque, when, and their unit. Who and
 * when reuse the release-to-payee columns (`received_by_name`, `date_received`, with the teller
 * and time in `released_by` / `released_at`); only the unit is new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->string('payee_unit_name')->nullable()->after('date_received');
        });
    }

    public function down(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->dropColumn('payee_unit_name');
        });
    }
};
