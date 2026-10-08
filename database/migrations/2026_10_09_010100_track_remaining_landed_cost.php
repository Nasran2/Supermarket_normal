<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_stock_layers', fn (Blueprint $t) => $t->decimal('remaining_cost_total', 15, 2)->nullable());
        Schema::table('stock_layer_movements', fn (Blueprint $t) => $t->decimal('cost_total', 15, 2)->nullable());
        DB::table('product_stock_layers')->whereNotNull('inventory_cost_total')->where('original_quantity', '>', 0)->update(['remaining_cost_total' => DB::raw('inventory_cost_total - ROUND(inventory_cost_total * (original_quantity - remaining_quantity) / original_quantity, 2)')]);
    }

    public function down(): void
    {
        Schema::table('stock_layer_movements', fn (Blueprint $t) => $t->dropColumn('cost_total'));
        Schema::table('product_stock_layers', fn (Blueprint $t) => $t->dropColumn('remaining_cost_total'));
    }
};
