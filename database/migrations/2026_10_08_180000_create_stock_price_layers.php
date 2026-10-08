<?php

use App\Support\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', fn (Blueprint $t) => $t->unsignedTinyInteger('default_slot')->nullable()->unique());
        $unit = DB::table('units')->where('short_name', 'pcs')->first();
        if (! $unit) {
            $id = DB::table('units')->insertGetId(['name' => 'Piece', 'short_name' => 'pcs', 'allow_decimal' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        } else {
            $id = $unit->id;
        }
        DB::table('units')->where('id', $id)->update(['default_slot' => 1, 'active' => true]);
        Schema::create('product_stock_layers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('primary_unit_id')->constrained('units')->restrictOnDelete();
            $t->string('source_type', 30);
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('source_reference')->nullable();
            $t->foreignId('purchase_item_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('cost_price', 15, 2);
            $t->decimal('selling_price', 15, 2);
            $t->decimal('original_quantity', 18, 3);
            $t->decimal('remaining_quantity', 18, 3);
            $t->dateTime('received_at');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 20)->default('ACTIVE');
            $t->timestamps();
            $t->index(['product_id', 'status', 'selling_price', 'received_at'], 'stock_layer_fifo');
            $t->index(['source_type', 'source_id']);
            $t->index(['remaining_quantity', 'cost_price']);
        });
        Schema::create('sale_stock_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stock_layer_id')->constrained('product_stock_layers')->restrictOnDelete();
            $t->decimal('quantity', 18, 3);
            $t->decimal('returned_quantity', 18, 3)->default(0);
            $t->decimal('cost_price', 15, 2);
            $t->decimal('cost_total', 15, 2);
            $t->timestamps();
        });
        Schema::create('stock_layer_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_movement_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stock_layer_id')->constrained('product_stock_layers')->restrictOnDelete();
            $t->decimal('quantity', 18, 3);
            $t->decimal('cost_price', 15, 2);
            $t->decimal('selling_price', 15, 2);
            $t->timestamps();
        });
        Schema::table('sale_items', function (Blueprint $t) {
            $t->decimal('stock_price', 15, 2)->nullable();
            $t->decimal('cogs_total', 15, 2)->nullable();
        });
        Schema::table('purchase_items', function (Blueprint $t) {
            $t->decimal('selling_price', 15, 2)->nullable();
            $t->decimal('base_selling_price', 15, 2)->nullable();
        });
        // Import current balances once. Do not replay movements or rewrite historical sales.
        DB::table('products')->orderBy('id')->chunkById(200, function ($products) {
            foreach ($products as $p) {
                if (Money::compare($p->stock, 0) > 0) {
                    DB::table('product_stock_layers')->insert(['product_id' => $p->id, 'primary_unit_id' => $p->unit_id, 'source_type' => 'MIGRATED_STOCK', 'source_reference' => $p->sku, 'cost_price' => $p->cost, 'selling_price' => $p->price, 'original_quantity' => $p->stock, 'remaining_quantity' => $p->stock, 'received_at' => $p->created_at ?? now(), 'created_by' => $p->created_by, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
        foreach (['products.view_cost', 'products.manage_prices', 'purchases.manage_prices'] as $name) {
            $permission = DB::table('permissions')->where('name', $name)->value('id') ?? DB::table('permissions')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('roles')->whereIn('name', ['Administrator', 'Manager'])->pluck('id') as $role) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_layer_movements');
        Schema::dropIfExists('sale_stock_allocations');
        Schema::dropIfExists('product_stock_layers');
        Schema::table('sale_items', fn (Blueprint $t) => $t->dropColumn(['stock_price', 'cogs_total']));
        Schema::table('purchase_items', fn (Blueprint $t) => $t->dropColumn(['selling_price', 'base_selling_price']));
        Schema::table('units', function (Blueprint $t) {
            $t->dropUnique(['default_slot']);
            $t->dropColumn('default_slot');
        });
    }
};
