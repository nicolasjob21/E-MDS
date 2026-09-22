<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RTS becomes a status of its own, taken with its own fields: who received the record and when,
 * the RTS unit, the RTS date, and a required comment. The first two are new columns on the
 * history entry — the unit, date and comment reuse `unit_id`, `acted_on` and `note`. Each RTS
 * is one row; nothing is overwritten, so a record returned three times keeps all three.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lddap_routing_history', function (Blueprint $table) {
            $table->string('received_by_name')->nullable()->after('counterparty');
            $table->date('received_on')->nullable()->after('received_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('lddap_routing_history', function (Blueprint $table) {
            $table->dropColumn(['received_by_name', 'received_on']);
        });
    }
};
