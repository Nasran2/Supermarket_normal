<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->unsignedBigInteger('next_number')->default(1);
        });
        Schema::table('purchases', function (Blueprint $t) {
            $t->decimal('subtotal', 15, 2)->nullable();
            $t->decimal('charges_total', 15, 2)->default(0);
            $t->string('charge_treatment', 20)->default('EXPENSE');
        });
        Schema::create('purchase_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $t->string('label', 150);
            $t->string('status', 20)->default('ACTIVE');
            $t->decimal('amount', 15, 2);
            $t->foreignId('expense_id')->nullable()->constrained()->restrictOnDelete();
            $t->timestamps();
        });
        Schema::table('purchase_items', fn (Blueprint $t) => $t->decimal('allocated_charge', 15, 2)->default(0));
        Schema::table('product_stock_layers', fn (Blueprint $t) => $t->decimal('inventory_cost_total', 15, 2)->nullable());
        Schema::table('purchase_payments', function (Blueprint $t) {
            $t->decimal('amount_paid', 15, 2)->nullable();
            $t->decimal('change', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_payments', fn (Blueprint $t) => $t->dropColumn(['amount_paid', 'change']));
        Schema::table('product_stock_layers', fn (Blueprint $t) => $t->dropColumn('inventory_cost_total'));
        Schema::table('purchase_items', fn (Blueprint $t) => $t->dropColumn('allocated_charge'));
        Schema::dropIfExists('purchase_charges');
        Schema::table('purchases', fn (Blueprint $t) => $t->dropColumn(['subtotal', 'charges_total', 'charge_treatment']));
        Schema::dropIfExists('document_sequences');
    }
};
