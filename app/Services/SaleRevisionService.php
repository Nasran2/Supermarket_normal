<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Product;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleRevision;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleRevisionService
{
    public function __construct(private SaleService $sales, private SplitPaymentService $payments, private StockService $stock) {}

    public function assertEditable(Sale $sale, int $userId): void
    {
        $register = $sale->register;
        $user = User::find($userId);

        $isCreator = ! $register->closed_at && (int) $register->open_user_id === $userId;
        $isAdmin = $user && $user->isAdministrator();

        if ($sale->status !== 'ACTIVE' || (! $isCreator && ! $isAdmin)) {
            throw ValidationException::withMessages(['sale' => 'Invoice editing requires its original register to be open under your account, or administrator privileges.']);
        }
        if (SaleReturn::where('replacement_sale_id', $sale->id)->orWhere('sale_id', $sale->id)->exists() || $sale->returns()->exists() || $sale->collections()->exists()) {
            throw ValidationException::withMessages(['sale' => 'This invoice has returns or later due payments. Use returns or Pay due for further corrections.']);
        }
    }

    public function version(Sale $sale): string
    {
        return hash('sha256', json_encode([$sale->getAttributes(), $sale->items()->orderBy('id')->get()->map->getAttributes()->all(), $sale->payments()->orderBy('id')->get()->map->getAttributes()->all()]));
    }

    private function checkVersion(Sale $sale, array $data): void
    {
        if (! hash_equals($this->version($sale), $data['version'])) {
            throw ValidationException::withMessages(['sale' => 'This invoice changed in another window. Reload to edit its latest saved version.']);
        }
    }

    public function quote(Sale $sale, array $data, User $user): array
    {
        $this->assertEditable($sale, $user->id);
        $this->checkVersion($sale, $data);

        return $this->sales->quote($data, $user, false, $sale);
    }

    public function revise(Sale $original, array $data, User $user): Sale
    {
        return DB::transaction(function () use ($original, $data, $user) {
            Register::whereKey($original->register_id)->lockForUpdate()->firstOrFail();
            $sale = Sale::whereKey($original->id)->lockForUpdate()->firstOrFail();
            $existing = SaleRevision::where('token', $data['checkout_token'])->first();
            if ($existing) {
                abort_unless($existing->sale_id === $sale->id && $existing->user_id === $user->id, 403);

                return $sale;
            }
            $this->assertEditable($sale, $user->id);
            $this->checkVersion($sale, $data);
            $before = $sale->load('items.allocations', 'payments.expense')->toArray();
            $ids = $sale->items->pluck('product_id')->merge(array_column($data['items'], 'product_id'))->unique();
            $products = Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($sale->items as $item) {
                app(StockLayerService::class)->restore($products[$item->product_id], $item, $item->base_quantity ?? $item->quantity, 'SALE EDIT RESTORE', $sale->invoice, $user->id);
            }
            $quote = $this->sales->quote($data, $user, true);
            if (! hash_equals($quote['quote_hash'], $data['quote_hash'])) {
                throw ValidationException::withMessages(['payment' => 'Prices, stock or payment rules changed. Review payment again before saving.']);
            }
            $payments = $this->payments->settle($data, $quote);
            // Preserve reversed expenses and full payment snapshots in revision history.
            Expense::where('sale_id', $sale->id)->where('type', 'AUTOMATIC')->update(['status' => 'REVERSED', 'sale_payment_id' => null]);
            $sale->items()->delete();
            $sale->payments()->delete();
            $sale->update(['customer_id' => $data['customer_id'] ?? null, 'notes' => $data['notes'] ?? null, 'subtotal' => $quote['subtotal'], 'discount' => $quote['discount'], 'sale_amount' => $quote['sale_amount'], 'processing_charge' => $quote['processing_charge'], 'customer_payable' => $quote['customer_payable'], 'cost_total' => $quote['cost_total']]);
            foreach ($quote['items'] as $line) {
                $item = $sale->items()->create(array_diff_key($line, ['allocations' => true]));
                $product = $products[$line['product_id']]->refresh();
                app(StockLayerService::class)->consume($product, $item, $line['allocations'], 'SALE EDIT', $sale->invoice, $user->id);
            }
            foreach ($payments as $part) {
                $payment = $sale->payments()->create(['payment_method_id' => $part['payment_method_id'], 'payment_charge_rule_id' => $part['rule_id'], 'method_name' => $part['method_name'], 'method_type' => $part['method_type'], 'rule_name' => $part['rule_name'], 'charge_type' => $part['charge_type'], 'charge_value' => $part['charge_value'], 'charge_bearer' => $part['charge_bearer'], 'sale_amount' => $part['sale_amount'], 'processing_charge' => $part['processing_charge'], 'customer_payable' => $part['customer_payable'], 'amount_paid' => $part['amount_paid'], 'change' => $part['change'], 'reference' => $part['reference']]);
                app(PaymentChargeService::class)->createProcessingExpense($payment);
            }
            $after = $sale->fresh(['items.allocations', 'payments.expense'])->toArray();
            $revision = SaleRevision::create(['sale_id' => $sale->id, 'user_id' => $user->id, 'token' => $data['checkout_token'], 'before' => $before, 'after' => $after]);
            Audit::record('sale.revise', $sale, $before, ['revision_id' => $revision->id, 'invoice' => $after]);

            return $sale->fresh();
        }, 3);
    }
}
