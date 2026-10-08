<?php

namespace App\Services;

use App\Models\PaymentChargeRule;
use App\Models\PaymentMethod;
use App\Models\ProductUnit;
use App\Models\PurchaseItem;
use App\Models\Register;
use App\Models\Role;
use App\Models\SaleItem;
use App\Models\StockAdjustmentItem;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\UnitPresetConversion;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Support\Resources;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ResourceService
{
    private function guardPermissions(array $ids): void
    {
        $allowed = auth()->user()->role->permissions->pluck('id')->all();
        if (array_diff($ids, $allowed)) {
            throw ValidationException::withMessages(['permissions' => 'You cannot grant permissions beyond your own access.']);
        }
    }

    public function guardEditable($resource, $record): void
    {
        if ($resource === 'expenses' && ($record->type !== 'MANUAL' || $record->status !== 'ACTIVE')) {
            throw ValidationException::withMessages(['expense' => 'Automatic or reversed expenses cannot be edited manually.']);
        }
        if ($resource === 'roles' && $record->name === 'Administrator') {
            throw ValidationException::withMessages(['role' => 'Administrator permissions are protected.']);
        }
        if ($resource === 'expense-categories' && $record->system) {
            throw ValidationException::withMessages(['category' => 'This system category is protected.']);
        }
    }

    public function save(string $resource, array $data, ?int $id = null): void
    {
        $def = Resources::get($resource);
        $path = null;
        if (($data['image'] ?? null) instanceof UploadedFile) {
            $path = $data['image']->store('products', 'public');
            $data['image'] = $path;
        }
        try {
            DB::transaction(function () use ($resource, $def, $data, $id) {
                $record = $id ? $def['model']::whereKey($id)->lockForUpdate()->firstOrFail() : new $def['model'];
                if ($id) {
                    $this->guardEditable($resource, $record);
                }
                $before = $record->getAttributes();
                $values = $data;
                unset($values['conversions']);
                if (in_array($resource, ['products', 'unit-presets']) && $id) {
                    $before['conversions'] = $record->conversions->toArray();
                }
                if ($resource === 'unit-presets' || ($resource === 'products' && array_key_exists('conversions', $data))) {
                    $base = Unit::whereKey($data['unit_id'])->lockForUpdate()->firstOrFail();
                    app(ProductUnitService::class)->rows($base, $data['conversions'] ?? []);
                }

                if ($resource === 'customers' && (array_key_exists('opening_due', $values) || ! $id)) {
                    $values['opening_due'] = Money::round((string) ($values['opening_due'] ?? 0));
                }
                if ($resource === 'payment-rules' && ($data['charge_bearer'] ?? null) === 'DEFAULT') {
                    $values['charge_bearer'] = null;
                }
                if ($resource === 'roles' && $id) {
                    $before['permissions'] = $record->permissions->pluck('id')->all();
                }
                if ($resource === 'units' && $id && $record->allow_decimal && ! $data['allow_decimal']) {
                    foreach ([SaleItem::class, PurchaseItem::class] as $itemModel) {
                        if ($itemModel::where('unit_id', $id)->whereRaw('quantity <> ROUND(quantity, 0)')->exists()) {
                            throw ValidationException::withMessages(['allow_decimal' => 'This unit has fractional transaction history.']);
                        }
                    }
                    if (StockAdjustmentItem::where('unit_id', $id)->where(fn ($q) => $q->whereRaw('stock_before <> ROUND(stock_before, 0)')->orWhereRaw('stock_after <> ROUND(stock_after, 0)')->orWhereRaw('quantity_change <> ROUND(quantity_change, 0)'))->exists()) {
                        throw ValidationException::withMessages(['allow_decimal' => 'This unit has fractional stock adjustment history.']);
                    }
                    foreach ([ProductUnit::class, UnitPresetConversion::class] as $conversionModel) {
                        if ($conversionModel::where('unit_id', $id)->whereRaw('converted_quantity <> ROUND(converted_quantity, 0)')->exists()) {
                            throw ValidationException::withMessages(['allow_decimal' => 'This unit is used in fractional conversions.']);
                        }
                    }
                    foreach ($record->products()->cursor() as $p) {
                        if ($p->saleItems()->whereRaw('COALESCE(base_quantity, quantity) <> ROUND(COALESCE(base_quantity, quantity), 0)')->exists() || $p->purchaseItems()->whereRaw('COALESCE(base_quantity, quantity) <> ROUND(COALESCE(base_quantity, quantity), 0)')->exists()) {
                            throw ValidationException::withMessages(['allow_decimal' => 'This unit has a history of fractional transactions.']);
                        }
                        if ($p->conversions()->whereRaw('base_quantity <> ROUND(base_quantity, 0)')->exists()) {
                            throw ValidationException::withMessages(['allow_decimal' => 'This unit is used in fractional primary conversions.']);
                        }
                        foreach ([$p->stock, $p->low_stock] as $q) {
                            if (Money::compare($q, (string) intval($q)) !== 0) {
                                throw ValidationException::withMessages(['allow_decimal' => 'This unit is used by products with fractional stock or alert levels.']);
                            }
                        }
                    }
                    if (UnitPresetConversion::whereHas('preset', fn ($q) => $q->where('unit_id', $id))->whereRaw('base_quantity <> ROUND(base_quantity, 0)')->exists()) {
                        throw ValidationException::withMessages(['allow_decimal' => 'This unit is used in fractional preset conversions.']);
                    }
                }
                if ($resource === 'products') {
                    $unit = Unit::whereKey($data['unit_id'])->lockForUpdate()->firstOrFail();
                    if ($id && $record->unit_id != $unit->id && ($record->saleItems()->exists() || $record->purchaseItems()->exists() || StockMovement::where('product_id', $record->id)->exists() || StockAdjustmentItem::where('product_id', $record->id)->exists() || Money::compare($record->stock, 0) !== 0)) {
                        throw ValidationException::withMessages(['unit_id' => 'Units cannot change after stock or transactions exist.']);
                    }
                    if ($id && $record->unit_id != $unit->id && ! array_key_exists('conversions', $data)) {
                        $record->conversions()->delete();
                        $record->unsetRelation('conversions');
                    }
                    if (! $unit->active) {
                        throw ValidationException::withMessages(['unit_id' => 'Choose an active primary unit.']);
                    }
                    if (! $unit->allow_decimal && Money::compare($data['low_stock'], (string) intval($data['low_stock'])) !== 0) {
                        throw ValidationException::withMessages(['low_stock' => 'This unit requires a whole quantity.']);
                    }
                    $values['updated_by'] = auth()->id();
                    if (! $id) {
                        $values['created_by'] = auth()->id();
                        $values['stock'] = '0.000';
                    } else {
                        unset($values['stock']);
                    }
                }
                if ($resource === 'users') {
                    $target = Role::with('permissions')->findOrFail($data['role_id']);
                    $this->guardPermissions($target->permissions->pluck('id')->all());
                    if ($id === auth()->id() && (! $data['active'] || $data['role_id'] != auth()->user()->role_id)) {
                        throw ValidationException::withMessages(['active' => 'You cannot disable yourself or change your own role.']);
                    }
                    if ($id && $record->role?->name === 'Administrator' && (! $data['active'] || Role::find($data['role_id'])?->name !== 'Administrator') && User::where('role_id', $record->role_id)->where('active', true)->count() <= 1) {
                        throw ValidationException::withMessages(['role_id' => 'Keep at least one active administrator.']);
                    }
                    if (empty($values['password'])) {
                        unset($values['password']);
                    }
                }
                if ($resource === 'payment-rules') {
                    PaymentMethod::whereKey($data['payment_method_id'])->lockForUpdate()->firstOrFail();
                }
                if ($resource === 'payment-rules' && $data['active']) {
                    $others = PaymentChargeRule::where('payment_method_id', $data['payment_method_id'])->where('active', true)->where('priority', $data['priority'])->when($id, fn ($q) => $q->where('id', '!=', $id))->get();
                    foreach ($others as $other) {
                        $disjoint = (($data['maximum_amount'] ?? null) !== null && (Money::compare($data['maximum_amount'], $other->minimum_amount) < 0 || (Money::compare($data['maximum_amount'], $other->minimum_amount) === 0 && $other->comparison_operator === 'GT'))) || ($other->maximum_amount !== null && (Money::compare($other->maximum_amount, $data['minimum_amount']) < 0 || (Money::compare($other->maximum_amount, $data['minimum_amount']) === 0 && $data['comparison_operator'] === 'GT')));
                        if (! $disjoint) {
                            throw ValidationException::withMessages(['priority' => 'Overlapping active rules must have different priorities. Higher priority wins.']);
                        }
                    }
                }
                if ($resource === 'expenses') {
                    $r = app(RegisterService::class)->current(auth()->id(), true);
                    if ($id && $record->register_id) {
                        $previous = Register::whereKey($record->register_id)->lockForUpdate()->firstOrFail();
                        if ($previous->closed_at) {
                            throw ValidationException::withMessages(['expense' => 'This expense belongs to a closed register.']);
                        }
                    }
                    $method = PaymentMethod::whereKey($values['payment_method_id'])->where('active', true)->lockForUpdate()->first();
                    if (! $method) {
                        throw ValidationException::withMessages(['payment_method_id' => 'Select an active payment method.']);
                    }
                    if ($method->type === 'CASH' && ! $r) {
                        throw ValidationException::withMessages(['amount' => 'Open your register to record a cash expense.']);
                    }
                    if ($id && $record->register_id && $record->register_id !== $r?->id) {
                        throw ValidationException::withMessages(['expense' => 'Edit cash expenses from their original register.']);
                    }
                    $values['register_id'] = $method->type === 'CASH' ? $r->id : null;
                    if (! $id) {
                        $values['user_id'] = auth()->id();
                    }
                }
                $permissionIds = $values['permissions'] ?? [];
                if ($resource === 'roles') {
                    $this->guardPermissions($permissionIds);
                }
                unset($values['permissions']);
                $record->fill($values);
                $record->save();
                if (in_array($resource, ['products', 'unit-presets']) && (array_key_exists('conversions', $data) || $resource === 'unit-presets')) {
                    $record->conversions()->delete();
                    $record->conversions()->createMany($data['conversions'] ?? []);
                    $record->unsetRelation('conversions');
                }
                if ($resource === 'products') {
                    $record->unsetRelation('unit');
                    app(ProductUnitService::class)->options($record);
                }
                if ($resource === 'roles') {
                    $record->permissions()->sync($permissionIds);
                }
                if ($resource === 'products' && ! $id && Money::compare((string) $data['stock'], 0) > 0) {
                    $record->load('unit');
                    app(StockService::class)->validateQuantity($record, (string) $data['stock']);
                    app(StockService::class)->move($record, (string) $data['stock'], 'OPENING STOCK', $record->sku, auth()->id());
                }
                unset($before['password']);
                $after = $record->getAttributes();
                if (in_array($resource, ['products', 'unit-presets'])) {
                    $after['conversions'] = $record->conversions->toArray();
                }

                if ($resource === 'roles') {
                    $after['permissions'] = $permissionIds;
                }
                unset($after['password']);
                Audit::record($resource.'.save', $record, $before, $after);
            }, 3);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
            throw $e;
        }

    }

    public function delete(string $resource, int $id): void
    {
        $def = Resources::get($resource);
        DB::transaction(function () use ($resource, $def, $id) {
            $record = $def['model']::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->guardEditable($resource, $record);
            if ($resource === 'customers' && Money::compare($record->due_balance, 0) > 0) {
                throw ValidationException::withMessages(['delete' => 'This customer has an outstanding due. Deactivate the account to preserve its balance.']);
            }
            if ($resource === 'roles' && $record->system) {
                throw ValidationException::withMessages(['role' => 'System roles cannot be deleted.']);
            }
            if ($resource === 'users' && $id === auth()->id()) {
                throw ValidationException::withMessages(['user' => 'You cannot delete your own account.']);
            }
            if ($resource === 'expenses') {
                if ($record->register_id && Register::whereKey($record->register_id)->lockForUpdate()->firstOrFail()->closed_at) {
                    throw ValidationException::withMessages(['expense' => 'This register is closed.']);
                }
                $record->update(['status' => 'REVERSED']);
                Audit::record('expense.reverse', $record);

                return;
            }
            Audit::record($resource.'.delete', $record, $record->getAttributes());
            $record->delete();
        }, 3);
    }
}
