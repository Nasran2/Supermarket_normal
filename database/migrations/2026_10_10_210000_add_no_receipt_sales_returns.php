<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_returns', function (Blueprint $t) {
            $t->foreignId('sale_id')->nullable()->change();
            $t->string('return_type', 20)->default('INVOICE')->index();
            $t->string('verification_status', 30)->default('VERIFIED')->index();
            $t->timestamp('approved_at')->nullable();
            foreach (['verified_amount', 'historical_cost_total', 'estimated_cost_total', 'unverified_amount'] as $field) {
                $t->decimal($field, 15, 2)->default(0);
            }
        });
        DB::table('sale_returns')->update(['verified_amount' => DB::raw('amount'), 'historical_cost_total' => DB::raw('cost_total')]);
        Schema::table('sale_return_items', function (Blueprint $t) {
            $t->foreignId('sale_item_id')->nullable()->change();
            $t->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('name')->nullable();
            $t->string('unit')->nullable();
            $t->decimal('suggested_credit_price', 15, 2)->nullable();
            $t->decimal('credit_price', 15, 2)->nullable();
            $t->string('credit_price_source', 40)->default('ORIGINAL_SALE');
            $t->foreignId('price_changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('price_override_reason')->nullable();
            $t->decimal('cost_basis', 15, 2)->nullable();
            $t->string('cost_basis_type', 20)->default('HISTORICAL');
            $t->string('cost_basis_source', 40)->default('ORIGINAL_ALLOCATION');
            $t->string('cost_basis_reference')->nullable();
            $t->decimal('stock_selling_price', 15, 2)->nullable();
            $t->decimal('fee_refund', 15, 2)->default(0);
            $t->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $t->date('purchased_on')->nullable();
            $t->text('notes')->nullable();
        });
        foreach (DB::table('sale_return_items')->join('sale_items', 'sale_items.id', '=', 'sale_return_items.sale_item_id')->select('sale_return_items.id', 'sale_items.product_id', 'sale_items.unit_id', 'sale_items.name', 'sale_items.unit')->cursor() as $row) {
            DB::table('sale_return_items')->where('id', $row->id)->update(['product_id' => $row->product_id, 'unit_id' => $row->unit_id, 'name' => $row->name, 'unit' => $row->unit]);
        }
        Schema::table('return_stock_allocations', fn (Blueprint $t) => $t->foreignId('stock_layer_id')->nullable()->change());
        foreach (config('pos.returns') as $key => $field) {
            DB::table('settings')->insertOrIgnore(['key' => $key, 'group' => 'returns', 'value' => json_encode($field[2]), 'created_at' => now(), 'updated_at' => now()]);
        }
        Cache::forget('business_settings');
        Permissions::install();
    }

    public function down(): void
    {
        throw new RuntimeException('No-receipt return audit records must not be discarded. Restore a verified backup.');
    }
};
