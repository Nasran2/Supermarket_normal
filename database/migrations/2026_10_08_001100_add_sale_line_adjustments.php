<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('catalog_price', 15, 2)->nullable();
            $table->decimal('line_subtotal', 15, 2)->nullable();
            $table->decimal('line_discount', 15, 2)->default(0);
            $table->string('discount_type', 16)->default('AMOUNT');
            $table->decimal('discount_value', 15, 2)->default(0);
        });
        DB::table('sale_items')->update(['catalog_price' => DB::raw('price'), 'line_subtotal' => DB::raw('total')]);
    }

    public function down(): void
    {
        if (DB::table('sale_items')->where('line_discount', '>', 0)->orWhereColumn('catalog_price', '!=', 'price')->exists()) {
            throw new RuntimeException('Adjusted sale lines exist; rollback would discard pricing history.');
        }
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn(['catalog_price', 'line_subtotal', 'line_discount', 'discount_type', 'discount_value']));
    }
};
