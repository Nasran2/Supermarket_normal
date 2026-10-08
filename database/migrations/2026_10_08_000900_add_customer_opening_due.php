<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('opening_due', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        if (DB::table('customers')->where('opening_due', '>', 0)->exists()) {
            throw new RuntimeException('Customer opening dues exist; reverting would discard outstanding balances.');
        }
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('opening_due');
        });
    }
};
