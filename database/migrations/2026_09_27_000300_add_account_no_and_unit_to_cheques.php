<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two more details recorded when a cheque is used: the payee's account number (text, so leading
 * zeros are kept) and the PCG unit (a name from config/pcg-units.json). Both optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->string('account_no')->nullable()->after('payee_name');
            $table->string('unit_name')->nullable()->after('account_no');
        });
    }

    public function down(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->dropColumn(['account_no', 'unit_name']);
        });
    }
};
