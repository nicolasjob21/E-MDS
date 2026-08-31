<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real LDDAP-ADA check numbers run to ten digits (e.g. 9910031148), which overflows a
     * 4-byte `integer` (max 2,147,483,647). The series column is widened to a big integer so
     * the numbers people actually hold can be registered.
     */
    public function up(): void
    {
        Schema::table('lddap_checks', function (Blueprint $table) {
            $table->unsignedBigInteger('check_no')->change();
        });
    }

    public function down(): void
    {
        Schema::table('lddap_checks', function (Blueprint $table) {
            $table->unsignedInteger('check_no')->change();
        });
    }
};
