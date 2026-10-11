<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('track_stock')->default(true);
            $table->string('default_discount_type', 10)->default('NONE');
            $table->decimal('default_discount_value', 11, 2)->default(0);
        });
        foreach (['sale_items', 'purchase_items', 'sale_return_items'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->boolean('track_stock')->default(true);
                if ($name === 'sale_items') {
                    $table->boolean('use_default_discount')->default(false);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['sale_items', 'purchase_items', 'sale_return_items'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropColumn($name === 'sale_items' ? ['track_stock', 'use_default_discount'] : ['track_stock']);
            });
        }
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['track_stock', 'default_discount_type', 'default_discount_value']));
    }
};
