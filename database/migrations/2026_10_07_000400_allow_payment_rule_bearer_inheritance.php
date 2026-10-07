<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_charge_rules', fn (Blueprint $t) => $t->string('charge_bearer')->nullable()->change());
    }

    public function down(): void
    {
        DB::table('payment_charge_rules')->whereNull('charge_bearer')->update(['charge_bearer' => 'CUSTOMER']);
        Schema::table('payment_charge_rules', fn (Blueprint $t) => $t->string('charge_bearer')->nullable(false)->change());
    }
};
