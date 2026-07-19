<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            // Captured when the cheque is used.
            $table->string('payee_name')->nullable()->after('cheque_number');
            $table->decimal('amount', 15, 2)->nullable()->after('payee_name');
            $table->date('cheque_date')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->dropColumn(['payee_name', 'amount', 'cheque_date']);
        });
    }
};
