<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TestProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = \Faker\Factory::create();
        $unit = \App\Models\Unit::firstOrCreate(['short_name' => 'pcs'], ['name' => 'Piece', 'allow_decimal' => false, 'active' => true]);
        $categories = \App\Models\Category::take(3)->get();
        if ($categories->isEmpty()) {
            $categories = collect([\App\Models\Category::firstOrCreate(['name' => 'General'])]);
        }

        for ($i = 0; $i < 50; $i++) {
            $cost = $faker->randomFloat(2, 10, 500);
            $price = $cost * $faker->randomFloat(2, 1.1, 1.5);
            $qty = $faker->numberBetween(10, 100);

            $product = \App\Models\Product::create([
                'name' => $faker->words(3, true),
                'sku' => strtoupper($faker->unique()->bothify('PROD-####-???')),
                'barcode' => $faker->unique()->ean13(),
                'unit_id' => $unit->id,
                'cost' => $cost,
                'price' => $price,
                'stock' => $qty,
                'low_stock' => 5,
                'active' => true,
            ]);

            if ($categories->isNotEmpty()) {
                $product->categories()->attach($categories->random()->id);
            }

            \App\Models\ProductStockLayer::create([
                'product_id' => $product->id,
                'primary_unit_id' => $unit->id,
                'source_type' => 'App\Models\StockAdjustment',
                'source_id' => null,
                'source_reference' => 'TEST-SEED',
                'status' => 'ACTIVE',
                'cost_price' => $cost,
                'selling_price' => $price,
                'original_quantity' => $qty,
                'remaining_quantity' => $qty,
                'received_at' => now(),
            ]);
        }
    }
}
