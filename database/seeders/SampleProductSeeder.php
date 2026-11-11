<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\Repair\RepairWorkflow;
use App\Services\StockLayerService;
use App\Support\Audit;
use App\Support\Expiry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SampleProductSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('username', 'admin')->first() ?? User::whereHas('role', fn ($q) => $q->where('name', 'Administrator'))->firstOrFail();
        auth()->setUser($user);
        $this->seedFor($user);
    }

    public function seedFor(User $user, ?string $shopType = null, array $modules = []): void
    {
        $profile = config('installer.shops')[$shopType ?? 'supermarket'];
        $expiry = in_array('EXPIRE', $modules, true);
        $repairs = in_array('PHONE_REP', $modules, true);

        DB::transaction(function () use ($profile, $user, $expiry, $repairs, $shopType) {
            foreach ($profile['products'] as $row) {
                [$code, $name, $category, $unit, $cost, $price, $quantity] = $row;
                $options = $row[7] ?? [];
                $days = $expiry ? ($options['expiry_days'] ?? null) : null;
                $repairOnly = $repairs && ($options['repair_only'] ?? false);
                $sku = 'DEMO-'.$code;
                $product = Product::firstOrCreate(['sku' => $sku], [
                    'name' => $name, 'barcode' => '2000000000'.$code, 'barcode_type' => 'CODE128',
                    'unit_id' => Unit::where('short_name', $unit)->where('active', true)->firstOrFail()->id,
                    'cost' => $cost, 'price' => $price, 'stock' => '0', 'low_stock' => '5', 'active' => true,
                    'track_expiry' => $days !== null,
                    'available_for_pos' => ! $repairOnly,
                    'available_for_repairs' => $shopType === null || $repairOnly,
                    'created_by' => $user->id, 'updated_by' => $user->id,
                ]);
                $product->categories()->syncWithoutDetaching([Category::firstOrCreate(['name' => $category])->id]);
                if ($product->wasRecentlyCreated) {
                    $date = $days === null ? null : Expiry::today()->addDays($days)->toDateString();
                    app(StockLayerService::class)->adjust($product, $quantity, null, $cost, $price, $sku,
                        'SAMPLE OPENING STOCK', $user->id, $date, $date === null ? null : 'BATCH-'.$sku);
                    Audit::record('product.sample-created', $product, [], $product->fresh()->toArray());
                }
            }
            if ($shopType === 'mobile' && $repairs) {
                $this->seedRepair($user);
            }
        });
    }

    private function seedRepair(User $user): void
    {
        $customer = Customer::firstOrCreate(['name' => 'Demo Repair Customer'], ['active' => true]);
        app(RepairWorkflow::class)->receive([
            'submission' => 'a5124524-59bc-4f4b-b4ad-22cb2f6d2cf7',
            'customer_id' => $customer->id,
            'brand' => 'Samsung',
            'model' => 'Galaxy A15',
            'faults' => 'Demo: cracked display; device powers on.',
            'received_at' => now(),
            'due_at' => today()->addDays(3),
            'priority' => 'NORMAL',
            'estimate' => '8500',
            'checklist' => [['label' => 'Power on', 'result' => 'Working']],
        ], $user);
    }
}
