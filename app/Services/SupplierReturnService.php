<?php

namespace App\Services;

use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class SupplierReturnService
{
    public function transition(SupplierReturn $original, array $data, User $user): void
    {
        abort_unless($user->hasPermission('supplier_returns.manage'), 403);
        DB::transaction(function () use ($original, $data, $user) {
            $r = SupplierReturn::whereKey($original->id)->lockForUpdate()->firstOrFail();
            abort_unless(SaleReturn::visibleTo($user)->whereKey($r->sale_return_id)->exists(), 403);
            Supplier::whereKey($r->supplier_id)->lockForUpdate()->firstOrFail();
            if ($data['action'] === 'SEND') {
                if ($r->status !== 'PENDING') {
                    ReturnSettlementService::fail('Only pending supplier returns can be sent.');
                }
                $r->update(['status' => 'SENT', 'sent_at' => now()]);
            } else {
                if ($r->status !== 'SENT') {
                    ReturnSettlementService::fail('Send the supplier return before settling it.');
                }
                $apply = Money::round((string) ($data['apply_due'] ?? 0));
                if (Money::compare($apply, 0) < 0 || Money::compare($apply, $r->amount) > 0) {
                    ReturnSettlementService::fail('Supplier due allocation exceeds this return value.');
                }
                $service = app(ReturnSettlementService::class);
                $service->applySupplier($r, 'supplier_return', $r->supplier_id, $apply, null, $user);
                $remaining = Money::sub($r->amount, $apply);
                if (Money::compare($remaining, 0) > 0) {
                    abort_unless($user->hasPermission('purchase_returns.receive_refund'), 403);
                    $service->money($r, 'supplier_return', 'SUPPLIER_RETURN_REFUND', $remaining, $data['payment_method_id'] ?? null, $user);
                }
                $r->update(['status' => 'SETTLED', 'settled_at' => now(), 'settled_by' => $user->id]);
            }
            Audit::record('supplier_return.'.strtolower($data['action']), $r, [], ['status' => $r->status, 'notes' => $data['notes'] ?? null]);
        }, 3);
    }
}
