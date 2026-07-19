<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            // Bank encashment — recorded when the cheque is received by a teller and turned into money.
            $table->string('teller_name')->nullable()->after('used_at');
            $table->date('cashed_at')->nullable()->after('teller_name');
        });
    }

    public function down(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->dropColumn(['teller_name', 'cashed_at']);
        });
    }
};
