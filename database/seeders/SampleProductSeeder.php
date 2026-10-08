<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use App\Support\Audit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SampleProductSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('username', 'admin')->first() ?? User::whereHas('role', fn ($q) => $q->where('name', 'Administrator'))->firstOrFail();
        auth()->setUser($user);
        $samples = [
            ['001', 'White Rice 1kg', 'Groceries', 'pcs', '210', '260', '80'],
            ['002', 'Red Lentils 500g', 'Groceries', 'pcs', '240', '300', '60'],
            ['003', 'White Sugar 1kg', 'Groceries', 'pcs', '260', '320', '70'],
            ['004', 'Wheat Flour 1kg', 'Groceries', 'pcs', '190', '240', '50'],
            ['005', 'Fresh Milk 1L', 'Drinks', 'pcs', '380', '460', '40'],
            ['006', 'Drinking Water 1.5L', 'Drinks', 'pcs', '110', '150', '100'],
            ['007', 'Orange Juice 1L', 'Drinks', 'pcs', '520', '650', '30'],
            ['008', 'Ceylon Tea 200g', 'Groceries', 'pcs', '390', '490', '45'],
            ['009', 'Cream Crackers 200g', 'Snacks', 'pcs', '190', '250', '65'],
            ['010', 'Chocolate Biscuits', 'Snacks', 'pcs', '240', '320', '55'],
            ['011', 'Potato Chips 100g', 'Snacks', 'pcs', '290', '390', '35'],
            ['012', 'Coconut Oil 500ml', 'Groceries', 'pcs', '580', '720', '40'],
            ['013', 'Bath Soap', 'Household', 'pcs', '130', '180', '75'],
            ['014', 'Laundry Powder 1kg', 'Household', 'pcs', '620', '780', '30'],
            ['015', 'Bananas', 'Fresh produce', 'kg', '180', '240', '25'],
            ['016', 'Tomatoes', 'Fresh produce', 'kg', '250', '340', '20'],
        ];
        DB::transaction(function () use ($samples, $user) {
            foreach ($samples as [$code, $name, $category, $unit, $cost, $price, $quantity]) {
                $product = Product::firstOrCreate(['sku' => 'DEMO-'.$code], [
                    'name' => $name, 'barcode' => '2000000000'.$code,
                    'unit_id' => Unit::where('short_name', $unit)->where('active', true)->firstOrFail()->id,
                    'cost' => $cost, 'price' => $price, 'stock' => '0', 'low_stock' => '5', 'active' => true,
                    'created_by' => $user->id, 'updated_by' => $user->id,
                ]);
                $product->categories()->syncWithoutDetaching([Category::firstOrCreate(['name' => $category])->id]);
                if ($product->wasRecentlyCreated) {
                    app(StockService::class)->move($product, $quantity, 'SAMPLE OPENING STOCK', 'DEMO-'.$code, $user->id);
                    Audit::record('product.sample-created', $product, [], $product->fresh()->toArray());
                }
            }
        });
    }
}
