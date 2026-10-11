<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('track_expiry')->default(false);
        });
        foreach (['product_stock_layers', 'purchase_items', 'sale_stock_allocations', 'sale_return_items'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->date('expires_at')->nullable();
                $table->string('batch_number', 100)->nullable();
            });
        }
        Schema::table('product_stock_layers', function (Blueprint $table) {
            $table->string('expiry_source', 40)->nullable();
            $table->index(['product_id', 'expires_at', 'id', 'remaining_quantity'], 'stock_expiry_available');
            $table->index(['expires_at', 'status', 'remaining_quantity'], 'expiry_report_available');
        });
        Schema::create('expiry_writeoffs', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->string('reference')->unique();
            $table->foreignId('stock_layer_id')->constrained('product_stock_layers');
            $table->decimal('quantity', 15, 3);
            $table->decimal('cost_total', 15, 2);
            $table->foreignId('expense_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->string('reason', 255);
            $table->timestamps();
        });
        if (Schema::hasTable('demo_cycles')) {
            foreach (DB::table('demo_cycles')->get() as $cycle) {
                $baseline = json_decode($cycle->baseline, true, flags: JSON_THROW_ON_ERROR);
                $baseline['expiry_writeoffs'] ??= [];
                DB::table('demo_cycles')->where('id', $cycle->id)->update(['baseline' => json_encode($baseline, JSON_THROW_ON_ERROR)]);
            }
        }
        Permissions::install();
    }

    public function down(): void
    {
        Schema::dropIfExists('expiry_writeoffs');
        Schema::table('product_stock_layers', function (Blueprint $table) {
            $table->dropIndex('stock_expiry_available');
            $table->dropIndex('expiry_report_available');
            $table->dropColumn('expiry_source');
        });
        foreach (['product_stock_layers', 'purchase_items', 'sale_stock_allocations', 'sale_return_items'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['expires_at', 'batch_number']));
        }
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('track_expiry'));
    }
};
