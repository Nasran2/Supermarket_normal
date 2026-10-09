<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('has_charge')->default(false)->after('type');
            $table->string('charge_type')->nullable()->after('has_charge');
            $table->decimal('charge_value', 15, 4)->default(0)->after('charge_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['has_charge', 'charge_type', 'charge_value']);
        });
    }
};
