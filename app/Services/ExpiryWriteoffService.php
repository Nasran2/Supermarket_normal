<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\ProductStockLayer;
use App\Models\User;
use App\Support\Audit;
use App\Support\Expiry;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpiryWriteoffService
{
    public function writeoff(int $layerId, string $quantity, string $reason, User $user, ?string $token = null): Expense
    {
        abort_unless(Expiry::enabled(), 404);
        abort_unless($user->hasPermission('expiry.writeoff'), 403);
        $token ??= (string) Str::uuid();

        return DB::transaction(function () use ($layerId, $quantity, $reason, $user, $token) {
            $productId = ProductStockLayer::whereKey($layerId)->value('product_id');
            $product = Product::with('unit')->whereKey($productId)->lockForUpdate()->firstOrFail();
            $layer = $product->stockLayers()->whereKey($layerId)->lockForUpdate()->firstOrFail();
            $existing = DB::table('expiry_writeoffs')->where('token', $token)->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->created_by === $user->id, 403);
                if ((int) $existing->stock_layer_id !== $layerId || Money::compare($existing->quantity, $quantity) !== 0 || $existing->reason !== $reason) {
                    throw ValidationException::withMessages(['quantity' => 'This confirmation was already used for another write-off. Reload the report.']);
                }

                return Expense::findOrFail($existing->expense_id);
            }
            if (! $product->track_stock || ! $product->track_expiry || $layer->status !== 'ACTIVE' || ! Expiry::expired($layer->expires_at) || Money::compare($quantity, 0) <= 0 || Money::compare($quantity, $layer->remaining_quantity) > 0) {
                throw ValidationException::withMessages(['quantity' => 'Choose an expired batch and a quantity within its remaining stock. It may already have been written off.']);
            }
            app(StockService::class)->validateQuantity($product, $quantity);
            $cost = app(StockReturnService::class)->portion($layer->stock_value, $quantity, $layer->remaining_quantity);
            $reference = 'EXP-WO-'.strtoupper((string) Str::ulid());
            $category = ExpenseCategory::firstOrCreate(['name' => 'Expired Stock Write-Off'], ['system' => true]);
            $expense = Expense::create(['expense_category_id' => $category->id, 'user_id' => $user->id, 'type' => 'EXPIRY_WRITEOFF', 'status' => 'ACTIVE', 'expense_date' => Expiry::today()->toDateString(), 'reference' => $reference, 'description' => $product->name.' · expiry '.$layer->expires_at->toDateString().' · '.$reason, 'amount' => $cost]);
            app(StockLayerService::class)->adjust($product, '-'.$quantity, $layer->id, $layer->cost_price, $layer->selling_price, $reference, 'EXPIRED STOCK WRITE-OFF', $user->id);
            DB::table('expiry_writeoffs')->insert(['token' => $token, 'reference' => $reference, 'stock_layer_id' => $layer->id, 'quantity' => $quantity, 'cost_total' => $cost, 'expense_id' => $expense->id, 'created_by' => $user->id, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('expiry.writeoff', $expense, [], ['layer' => $layer->id, 'quantity' => $quantity, 'cost' => $cost, 'expires_at' => $layer->expires_at->toDateString()]);

            return $expense;
        }, 3);
    }
}
