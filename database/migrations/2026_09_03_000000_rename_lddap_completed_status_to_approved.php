<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The positive LDDAP review outcome is now "Approved" rather than "Completed", matching the
     * cheque register's wording — only approved records may go on an ACIC.
     */
    public function up(): void
    {
        DB::table('lddaps')->where('status', 'completed')->update(['status' => 'approved']);
    }

    public function down(): void
    {
        DB::table('lddaps')->where('status', 'approved')->update(['status' => 'completed']);
    }
};
