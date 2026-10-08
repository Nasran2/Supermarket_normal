<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_presets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        foreach (['unit_preset_conversions', 'product_units'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $parent = $name === 'product_units' ? 'product' : 'unit_preset';
                $table->foreignId($parent.'_id')->constrained()->cascadeOnDelete();
                $table->foreignId('unit_id')->constrained()->restrictOnDelete();
                $table->decimal('base_quantity', 15, 6);
                $table->decimal('converted_quantity', 15, 6);
                if ($name === 'product_units') {
                    $table->decimal('price', 15, 2)->nullable();
                }
                $table->timestamps();
                $table->unique([$parent.'_id', 'unit_id']);
            });
        }
        foreach (['sale_items', 'purchase_items'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
                $table->decimal('base_quantity', 15, 3)->nullable();
                $table->decimal('base_cost', 15, 2)->nullable();
            });
            DB::table($name)->update(['base_quantity' => DB::raw('quantity'), 'base_cost' => DB::raw('cost')]);
        }
    }

    public function down(): void
    {
        if (DB::table('product_units')->exists() || DB::table('unit_presets')->exists() || DB::table('sale_items')->whereColumn('base_quantity', '!=', 'quantity')->exists() || DB::table('purchase_items')->whereColumn('base_quantity', '!=', 'quantity')->exists()) {
            throw new RuntimeException('Unit conversions exist; rollback would discard stock conversion history.');
        }
        foreach (['sale_items', 'purchase_items'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('unit_id');
                $table->dropColumn(['base_quantity', 'base_cost']);
            });
        }
        Schema::dropIfExists('product_units');
        Schema::dropIfExists('unit_preset_conversions');
        Schema::dropIfExists('unit_presets');
    }
};
