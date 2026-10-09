<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockLayerService;
use App\Support\Audit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BulkSampleProductSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('active', true)->whereHas('role', fn ($q) => $q->where('name', 'Administrator'))->firstOrFail();
        $previousUser = auth()->user();
        auth()->setUser($user);
        $catalog = [
            'Groceries' => ['Basmati Rice 1kg', 'Brown Rice 1kg', 'Samba Rice 1kg', 'Red Rice 1kg', 'Chickpeas 500g', 'Green Gram 500g', 'Black Gram 500g', 'Toor Dhal 500g', 'Semolina 500g', 'Corn Flour 250g', 'Rice Flour 1kg', 'Table Salt 1kg', 'Brown Sugar 1kg', 'Icing Sugar 500g', 'Coconut Milk 400ml', 'Sunflower Oil 1L', 'Olive Oil 250ml', 'Tomato Sauce 400g', 'Soy Sauce 200ml', 'Instant Noodles 100g'],
            'Drinks' => ['Apple Juice 1L', 'Mango Juice 1L', 'Pineapple Juice 1L', 'Grape Juice 1L', 'Mixed Fruit Juice 1L', 'Lemon Soda 1.5L', 'Cola 1.5L', 'Ginger Beer 1L', 'Orange Soda 1.5L', 'Soda Water 500ml', 'Mineral Water 500ml', 'Chocolate Milk 200ml', 'Vanilla Milk 200ml', 'Strawberry Milk 200ml', 'Soy Milk 1L', 'Almond Milk 1L', 'Coconut Water 330ml', 'Iced Tea 500ml', 'Energy Drink 250ml', 'Coffee Drink 240ml'],
            'Snacks' => ['Butter Cookies 200g', 'Ginger Biscuits 200g', 'Marie Biscuits 200g', 'Oat Cookies 150g', 'Lemon Puff 200g', 'Chocolate Wafers 100g', 'Vanilla Wafers 100g', 'Cheese Crackers 150g', 'Salted Peanuts 100g', 'Roasted Cashews 100g', 'Mixed Nuts 100g', 'Popcorn 80g', 'Corn Chips 100g', 'Banana Chips 100g', 'Sweet Potato Chips 100g', 'Milk Chocolate 100g', 'Dark Chocolate 100g', 'Fruit Candy 100g', 'Cereal Bar 40g', 'Raisins 200g'],
            'Household' => ['Dishwashing Liquid 500ml', 'Dishwashing Bar 200g', 'Laundry Liquid 1L', 'Fabric Softener 1L', 'Floor Cleaner 1L', 'Toilet Cleaner 500ml', 'Glass Cleaner 500ml', 'Bleach 1L', 'Disinfectant 500ml', 'Kitchen Towels 2 Rolls', 'Toilet Tissue 4 Rolls', 'Facial Tissues 100 Sheets', 'Garbage Bags 10 Pack', 'Aluminium Foil 10m', 'Cling Film 30m', 'Scrub Sponge 3 Pack', 'Matchbox 10 Pack', 'Candles 6 Pack', 'Air Freshener 300ml', 'Handwash 250ml'],
            'Fresh produce' => ['Carrots', 'Potatoes', 'Red Onions', 'Big Onions', 'Garlic', 'Ginger', 'Green Chillies', 'Capsicum', 'Cabbage', 'Cauliflower', 'Pumpkin', 'Cucumber', 'Brinjal', 'Bitter Gourd', 'Ladies Fingers', 'Green Beans', 'Beetroot', 'Apples', 'Oranges', 'Papaya'],
            'Personal care' => ['Herbal Shampoo 200ml', 'Anti Dandruff Shampoo 200ml', 'Hair Conditioner 200ml', 'Body Wash 250ml', 'Moisturising Soap 100g', 'Baby Soap 100g', 'Toothpaste 120g', 'Toothbrush Soft', 'Toothbrush Medium', 'Mouthwash 250ml', 'Face Wash 100ml', 'Body Lotion 200ml', 'Petroleum Jelly 50g', 'Baby Powder 100g', 'Deodorant 150ml', 'Shaving Cream 100g', 'Disposable Razors 5 Pack', 'Cotton Buds 100 Pack', 'Sanitary Pads 10 Pack', 'Wet Wipes 80 Pack'],
        ];
        $created = 0;
        try {
            DB::transaction(function () use ($catalog, $user, &$created) {
                $units = Unit::where('active', true)->whereIn('short_name', ['pcs', 'kg'])->get()->keyBy('short_name');
                $code = 0;
                foreach ($catalog as $categoryName => $names) {
                    $category = Category::firstOrCreate(['name' => $categoryName]);
                    foreach ($names as $name) {
                        $code++;
                        $sku = sprintf('SAMPLE-%03d', $code);
                        // Reruns never replenish stock or alter a product already used in a sale.
                        if (Product::where('sku', $sku)->exists()) {
                            continue;
                        }
                        $unit = $units->get($categoryName === 'Fresh produce' ? 'kg' : 'pcs');
                        if (! $unit) {
                            throw new \RuntimeException('Sample products require active Piece (pcs) and Kilogram (kg) units.');
                        }
                        $price = (string) (100 + ($code % 25) * 30);
                        $cost = (string) (70 + ($code % 25) * 22);
                        $product = Product::create([
                            'name' => $name.' (Sample)', 'sku' => $sku, 'barcode' => $this->barcode($code),
                            'unit_id' => $unit->id, 'cost' => $cost, 'price' => $price,
                            'stock' => '0', 'low_stock' => '5', 'active' => true,
                            'created_by' => $user->id, 'updated_by' => $user->id,
                        ]);
                        $product->categories()->attach($category);
                        $quantity = $code % 15 === 0 ? '0' : ($code % 15 === 1 ? '3' : (string) (20 + $code % 60));
                        if ($quantity !== '0') {
                            if ($unit->allow_decimal) {
                                $quantity .= '.500';
                            }
                            $layers = app(StockLayerService::class);
                            $layers->receive($product, $quantity, $cost, $price, 'SAMPLE_OPENING_STOCK', $sku, $user->id);
                            if ($code % 10 === 0 || $code % 10 === 5) {
                                $secondPrice = $code % 10 === 0 ? (string) ((int) $price + 25) : $price;
                                $layers->receive($product, '10', (string) ((int) $cost + 10), $secondPrice, 'SAMPLE_OPENING_STOCK', $sku.'-SECOND', $user->id);
                            }
                        }
                        Audit::record('product.sample-created', $product, [], $product->fresh()->toArray());
                        $created++;
                    }
                }
            });
        } finally {
            if ($previousUser) {
                auth()->setUser($previousUser);
            } else {
                auth()->forgetUser();
            }
        }
        $this->command?->info("Added {$created} sample products. Existing products and stock were preserved.");
    }

    private function barcode(int $code): string
    {
        $digits = sprintf('290000%06d', $code);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $digits[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return $digits.((10 - $sum % 10) % 10);
    }
}
