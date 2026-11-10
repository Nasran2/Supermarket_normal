<?php

namespace App\Services\Repair;

use App\Models\Product;
use App\Models\RepairJob;
use App\Models\RepairPart;
use App\Models\User;
use App\Services\StockLayerService;
use App\Services\StockService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RepairInventory
{
    public function __construct(private StockLayerService $layers, private StockService $stock) {}

    public function add(RepairJob $job, array $data, User $user): void
    {
        DB::transaction(function () use ($job, $data, $user) {
            $job = RepairJob::lockForUpdate()->findOrFail($job->id);
            if ($job->parts()->where('submission', $data['submission'])->exists()) {
                return;
            }
            $this->assertEditable($job);
            $product = $this->product($data['product_id']);
            $quantity = (string) $data['quantity'];
            $this->stock->validateQuantity($product, $quantity);
            $part = $job->parts()->create([
                'submission' => $data['submission'], 'product_id' => $product->id,
                'quantity' => $quantity, 'price' => $data['price'] ?? $product->price, 'name' => $product->name,
            ]);
            $job->events()->create(['kind' => 'PART', 'note' => 'Planned '.$part->name, 'user_id' => $user->id]);
        }, 3);
    }

    public function change(RepairJob $job, int $partId, string $action, User $user): void
    {
        DB::transaction(function () use ($job, $partId, $action, $user) {
            $job = RepairJob::lockForUpdate()->findOrFail($job->id);
            $part = $job->parts()->findOrFail($partId);
            if (($action === 'consume' && $part->state === 'CONSUMED') || ($action === 'remove' && $part->state === 'REMOVED')) {
                return;
            }
            $this->assertEditable($job);
            if ($action === 'consume') {
                abort_unless($part->state === 'PLANNED', 409);
                $product = $this->product($part->product_id);
                $this->stock->validateQuantity($product, $part->quantity);
                $reserved = [];
                $plan = $this->layers->plan($product, $part->quantity, null, $reserved, lock: true, anyPrice: true);
                if (Money::compare(Money::add($job->part_cost, $plan['cogs_total']), '99999999999999.99') > 0) {
                    throw ValidationException::withMessages(['quantity' => 'Total part cost exceeds the supported amount.']);
                }
                $this->layers->deductAllocations($product, $plan['allocations'], 'REPAIR CONSUMPTION', $job->invoiceNumber(), $user->id, repairJob: $job->id);
                $part->update(['state' => 'CONSUMED', 'consumed_at' => now(), 'allocations' => $plan['allocations'], 'cost' => $plan['cogs_total']]);
                $job->increment('part_cost', $part->cost);
            } else {
                if ($part->state === 'CONSUMED') {
                    $this->restore($job, $part, $user);
                    $job->decrement('part_cost', $part->cost);
                }
                $part->update(['state' => 'REMOVED']);
            }
            $job->events()->create(['kind' => 'PART', 'note' => ucfirst($action).' · '.$part->quantity.' × '.$part->name, 'user_id' => $user->id]);
        }, 3);
    }

    private function product(int $id): Product
    {
        $product = Product::with('unit')->whereKey($id)->where('active', true)->where('track_stock', true)
            ->whereHas('unit', fn ($q) => $q->where('active', true))->lockForUpdate()->first();
        if (! $product) {
            throw ValidationException::withMessages(['product_id' => 'Choose an active, stock-tracked repair product.']);
        }

        return $product;
    }

    private function assertEditable(RepairJob $job): void
    {
        if (! $job->editable()) {
            throw ValidationException::withMessages(['part' => 'Parts are locked on closed repairs.']);
        }
    }

    private function restore(RepairJob $job, RepairPart $part, User $user): void
    {
        $product = Product::lockForUpdate()->findOrFail($part->product_id);
        if (! $product->track_stock) {
            throw ValidationException::withMessages(['part' => 'Enable stock tracking for this product before restoring the part.']);
        }
        $moves = [];
        foreach ($part->allocations as $row) {
            $layer = $product->stockLayers()->whereKey($row['stock_layer_id'])->lockForUpdate()->firstOrFail();
            if ($layer->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['part' => 'The original stock batch is no longer active.']);
            }
            $layer->update([
                'remaining_quantity' => Money::quantity($layer->remaining_quantity, $row['quantity']),
                'remaining_cost_total' => $layer->remaining_cost_total === null ? null : Money::add($layer->remaining_cost_total, $row['cost_total']),
            ]);
            $moves[] = [$layer, $row['quantity'], $row['cost_total']];
        }
        $this->layers->movement($product, $moves, 'REPAIR PART RESTORED', $job->invoiceNumber(), $user->id, repairJob: $job->id);
    }
}
