<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropUnique(['sale_id', 'payment_method_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('sale_payments')->select('sale_id', 'payment_method_id')->groupBy('sale_id', 'payment_method_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Repeated payment history exists; reverting would discard financial records.');
        }
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->unique(['sale_id', 'payment_method_id']);
        });
    }
};
