<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // Migrate existing category_id from products
        DB::table('products')->whereNotNull('category_id')->orderBy('id')->chunk(100, function ($products) {
            $inserts = [];
            foreach ($products as $product) {
                $inserts[] = [
                    'category_id' => $product->category_id,
                    'product_id' => $product->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('category_product')->insert($inserts);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
        });

        DB::table('category_product')->orderBy('id')->chunk(100, function ($rows) {
            foreach ($rows as $row) {
                // Just assign the first category back
                DB::table('products')->where('id', $row->product_id)->update(['category_id' => $row->category_id]);
            }
        });

        Schema::dropIfExists('category_product');
    }
};
