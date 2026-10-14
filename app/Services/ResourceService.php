<?php

namespace App\Services;

use App\Models\PaymentChargeRule;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\ProductStockLayer;
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
use App\Support\Expiry;
use App\Support\Hr;
use App\Support\Money;
use App\Support\Permissions;
use App\Support\Resources;
use App\Support\SalesVisibility;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ResourceService
{
    private function guardPermissions(array $ids): void
    {
        if (auth()->user()->isAdministrator()) {
            return;
        }
        if (! config('features.barcode')) {
            $ids = array_diff($ids, Permission::where('name', 'like', 'barcode.%')->pluck('id')->all());
        }
        $ids = array_intersect($ids, Permission::whereIn('name', array_keys(Permissions::visible()))->pluck('id')->all());
        $allowed = auth()->user()->role->permissions->pluck('id')->all();
        if (array_diff($ids, $allowed)) {
            throw ValidationException::withMessages(['permissions' => 'You cannot grant permissions beyond your own access.']);
        }
    }

    public function guardEditable($resource, $record): void
    {
        if ($resource === 'users') {
            if ($record->role?->system && $record->role->name === 'Administrator' && ! auth()->user()->isAdministrator()) {
                throw ValidationException::withMessages(['role_id' => 'Only an administrator can manage Administrator accounts.']);
            }
            $this->guardPermissions($record->role?->permissions->pluck('id')->all() ?? []);
            $this->guardSalesVisibility($record->role);
        }
        if ($resource === 'roles' && ! auth()->user()->isAdministrator()) {
            $this->guardPermissions($record->permissions->pluck('id')->all());
            $this->guardSalesVisibility($record);
        }
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

    private function guardSalesVisibility(?Role $role, ?string $mode = null): void
    {
        if (! SalesVisibility::canGrant($mode ?? $role?->sales_visibility ?? 'ALL', $role)) {
            throw ValidationException::withMessages(['sales_visibility' => 'You cannot grant or manage sales visibility beyond your own access.']);
        }
    }

    public function save(string $resource, array $data, ?int $id = null)
    {
        $def = Resources::get($resource);
        $path = null;
        if (($data['image'] ?? null) instanceof UploadedFile) {
            $path = $data['image']->store('products', 'public');
            $data['image'] = $path;
        }
        try {
            $result = DB::transaction(function () use ($resource, $def, $data, $id) {
                $record = $id ? $def['model']::whereKey($id)->lockForUpdate()->firstOrFail() : new $def['model'];
                if ($id) {
                    $this->guardEditable($resource, $record);
                }
                if ($resource === 'products' && ! \App\Support\Units::enabled()) {
                    $data['unit_id'] = $id ? $record->unit_id : app(DefaultUnitService::class)->resolve()->id;
                    unset($data['conversions'], $data['conversions_present']);
                }
                $before = $record->getAttributes();
                $values = $data;
                if ($resource === 'roles') {
                    $values['sales_visibility'] = $data['sales_visibility'] ?? ($id ? $record->sales_visibility : 'OWN');
                    $this->guardSalesVisibility($record, $values['sales_visibility']);
                }
                unset($values['conversions'], $values['opening_layers']);
                if (in_array($resource, ['products', 'unit-presets']) && $id) {
                    $before['conversions'] = $record->conversions->toArray();
                }
                if ($resource === 'products' && $id) {
                    $before['categories'] = $record->categories->pluck('id')->toArray();
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
                if ($resource === 'units' && $id && $record->default_slot === 1 && ! $data['active']) {
                    throw ValidationException::withMessages(['active' => 'Choose another default unit before disabling this one.']);
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
                    if (! config('features.barcode')) {
                        unset($values['barcode'], $values['barcode_type']);
                    } else {
                        $barcodes = app(BarcodeService::class);
                        if (! auth()->user()->hasPermission('barcode.edit_product_barcode')) {
                            unset($values['barcode'], $values['barcode_type']);
                        }
                        if (! $id && empty($values['barcode']) && $barcodes->setting('barcode_generate_on_create', false) && auth()->user()->hasPermission('barcode.generate')) {
                            $values['barcode_type'] = $barcodes->setting('barcode_default_type', 'CODE128');
                            $values['barcode'] = $barcodes->generate($values['barcode_type']);
                        }
                        if (array_key_exists('barcode', $values)) {
                            $values['barcode'] = $values['barcode'] === '' ? null : $values['barcode'];
                            $values['barcode_type'] ??= $id ? $record->barcode_type : $barcodes->setting('barcode_default_type', 'CODE128');
                            if ($values['barcode'] !== null) {
                                $barcodes->validate($values['barcode'], $values['barcode_type']);
                                $barcodes->unique($values['barcode'], $id);
                            }
                        }
                        if ($barcodes->setting('barcode_required', false) && empty(array_key_exists('barcode', $values) ? $values['barcode'] : ($id ? $record->barcode : null))) {
                            throw ValidationException::withMessages(['barcode' => 'Barcode is required by Barcode Settings.']);
                        }
                    }
                    if (Expiry::enabled()) {
                        $values['track_expiry'] = (bool) ($data['track_expiry'] ?? ($id ? $record->track_expiry : Expiry::setting('expiry_default_tracking')));
                        if ($values['track_expiry'] && ! ($data['track_stock'] ?? $record->track_stock)) {
                            throw ValidationException::withMessages(['track_expiry' => 'Maintain stock must be enabled to track expiry batches.']);
                        }
                    } else {
                        unset($values['track_expiry']);
                    }
                    $values['track_stock'] = $data['track_stock'] ?? ($id ? $record->track_stock : true);
                    $values['default_discount_type'] = $data['default_discount_type'] ?? ($id ? $record->default_discount_type : 'NONE');
                    $values['default_discount_value'] = $values['default_discount_type'] === 'NONE' ? '0.00' : Money::round((string) ($data['default_discount_value'] ?? ($id ? $record->default_discount_value : 0)));
                    if ($id && ! auth()->user()->hasPermission('products.manage_prices') && ($values['default_discount_type'] !== $record->default_discount_type || Money::compare($values['default_discount_value'], $record->default_discount_value) !== 0)) {
                        throw ValidationException::withMessages(['default_discount_value' => 'You do not have permission to change default discounts.']);
                    }
                    if ($id && $record->track_stock && ! $values['track_stock'] && (Money::compare($record->stock, 0) !== 0 || $record->stockLayers()->available()->exists())) {
                        throw ValidationException::withMessages(['track_stock' => 'Adjust the remaining stock to zero before switching off stock tracking. Existing stock and history are preserved.']);
                    }
                    if (! $values['track_stock']) {
                        $values['low_stock'] = '0.000';
                        if (! $id && collect($data['opening_layers'] ?? [['quantity' => $data['stock'] ?? 0]])->contains(fn ($row) => Money::compare((string) $row['quantity'], 0) > 0)) {
                            throw ValidationException::withMessages(['track_stock' => 'Opening stock must be zero when stock tracking is off.']);
                        }
                    }
                    if ($id && ! auth()->user()->hasPermission('products.view_cost')) {
                        $data['cost'] = $record->cost;
                        $values['cost'] = $record->cost;
                    }
                    if ($id && ! auth()->user()->hasPermission('products.manage_prices') && (Money::compare($data['price'], $record->price) !== 0 || Money::compare($data['cost'], $record->cost) !== 0)) {
                        throw ValidationException::withMessages(['price' => 'You do not have permission to change default prices or costs.']);
                    }
                    if (! $id && ! auth()->user()->hasPermission('products.view_cost') && collect($data['opening_layers'] ?? [['cost' => $data['cost']]])->contains(fn ($row) => Money::compare($row['cost'], 0) !== 0)) {
                        throw ValidationException::withMessages(['cost' => 'You do not have permission to enter stock costs.']);
                    }
                    $unit = Unit::whereKey($data['unit_id'])->lockForUpdate()->firstOrFail();
                    if ($id && $record->unit_id != $unit->id && ($record->saleItems()->exists() || $record->purchaseItems()->exists() || StockMovement::where('product_id', $record->id)->exists() || StockAdjustmentItem::where('product_id', $record->id)->exists() || Money::compare($record->stock, 0) !== 0)) {
                        throw ValidationException::withMessages(['unit_id' => 'Units cannot change after stock or transactions exist.']);
                    }
                    if ($id && $record->unit_id != $unit->id && ! array_key_exists('conversions', $data)) {
                        $record->conversions()->delete();
                        $record->unsetRelation('conversions');
                    }
                    if (! $unit->active) {
                        throw ValidationException::withMessages([\App\Support\Units::enabled() ? 'unit_id' : 'product' => \App\Support\Units::enabled() ? 'Choose an active primary unit.' : 'This product is unavailable. Contact an administrator.']);
                    }
                    if (! $unit->allow_decimal && Money::compare($data['low_stock'], (string) intval($data['low_stock'])) !== 0) {
                        throw ValidationException::withMessages(['low_stock' => 'Enter a whole quantity.']);
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
                    if ($target->system && $target->name === 'Administrator' && ! auth()->user()->isAdministrator()) {
                        throw ValidationException::withMessages(['role_id' => 'Only an administrator can assign Administrator access.']);
                    }
                    if (! auth()->user()->isAdministrator() && (! $id || $record->role_id != $target->id)) {
                        $hiddenNames = array_diff(array_keys(Permissions::all()), array_keys(Permissions::visible()));
                        $hiddenTarget = $target->permissions->whereIn('name', $hiddenNames)->pluck('id')->all();
                        if (array_diff($hiddenTarget, auth()->user()->role->permissions->pluck('id')->all())) {
                            throw ValidationException::withMessages(['role_id' => 'You cannot assign a role with stored feature permissions beyond your own access.']);
                        }
                    }
                    $this->guardPermissions($target->permissions->pluck('id')->all());
                    $this->guardSalesVisibility($target);
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
                if ($resource === 'payment-methods') {
                    if ($id && $record->type === 'CHEQUE' && (($values['type'] ?? $record->type) !== 'CHEQUE' || ($values['code'] ?? $record->code) !== $record->code)) {
                        throw ValidationException::withMessages(['type' => 'The cheque payment type/code is protected. Disable the method or the CHEQUE feature instead.']);
                    }
                    if (($values['type'] ?? '') === 'CHEQUE') {
                        abort_unless(config('features.cheque'), 404);
                        if (PaymentMethod::where('type', 'CHEQUE')->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                            throw ValidationException::withMessages(['type' => 'Use the existing Cheque payment method.']);
                        }
                        $values['has_charge'] = false;
                        $values['charge_value'] = 0;
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
                    $method = PaymentMethod::where('type', '!=', 'CHEQUE')->whereKey($values['payment_method_id'])->where('active', true)->lockForUpdate()->first();
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
                    $hidden = Permission::whereNotIn('name', array_keys(Permissions::visible()))->pluck('id')->all();
                    if (array_intersect($data['permissions'] ?? [], $hidden)) {
                        throw ValidationException::withMessages(['permissions' => 'These feature permissions are disabled. Enable the module before changing them.']);
                    }
                    if ($id) {
                        $permissionIds = array_unique(array_merge($permissionIds, $record->permissions->whereIn('id', $hidden)->pluck('id')->all()));
                    }
                }
                if ($resource === 'roles' && ! \App\Support\Units::enabled()) {
                    $hidden = Permission::all()->filter(fn ($p) => \App\Support\Units::permission($p->name))->pluck('id')->all();
                    if (array_intersect($data['permissions'] ?? [], $hidden)) {
                        throw ValidationException::withMessages(['permissions' => 'These feature permissions are disabled. Enable the module before changing them.']);
                    }
                    if ($id) {
                        $permissionIds = array_unique(array_merge($permissionIds, $record->permissions->whereIn('id', $hidden)->pluck('id')->all()));
                    }
                }
                if ($resource === 'roles' && ! Hr::enabled()) {
                    $hiddenIds = Permission::where('name', 'like', 'hr.%')->pluck('id')->all();
                    if (array_intersect($data['permissions'] ?? [], $hiddenIds)) {
                        throw ValidationException::withMessages(['permissions' => 'HR is disabled. Enable the module before changing HR permissions.']);
                    }
                    if ($id) {
                        $permissionIds = array_unique(array_merge($permissionIds, $record->permissions->whereIn('id', $hiddenIds)->pluck('id')->all()));
                    }
                }
                if ($resource === 'roles' && ! config('features.barcode')) {
                    $hidden = Permission::where('name', 'like', 'barcode.%')->pluck('id')->all();
                    if (array_intersect($data['permissions'] ?? [], $hidden)) {
                        throw ValidationException::withMessages(['permissions' => 'Barcode is disabled. Enable the feature before changing barcode permissions.']);
                    }
                    if ($id) {
                        $permissionIds = array_unique(array_merge($permissionIds, $record->permissions->whereIn('id', $hidden)->pluck('id')->all()));
                    }
                }
                $categoryIds = $values['categories'] ?? [];
                if ($resource === 'roles') {
                    if (strcasecmp(trim($values['name']), 'Administrator') === 0) {
                        throw ValidationException::withMessages(['name' => 'Administrator is a protected system role.']);
                    }
                    $this->guardPermissions($permissionIds);
                }
                unset($values['permissions'], $values['categories']);
                $record->fill($values);
                $record->save();
                if (in_array($resource, ['products', 'unit-presets']) && (array_key_exists('conversions', $data) || $resource === 'unit-presets')) {
                    $record->conversions()->delete();
                    $record->conversions()->createMany($data['conversions'] ?? []);
                    $record->unsetRelation('conversions');
                }
                if ($resource === 'products') {
                    $record->categories()->sync($categoryIds);
                    $record->unsetRelation('unit');
                    $record->unsetRelation('categories');
                    app(ProductUnitService::class)->options($record);
                }
                if ($resource === 'roles') {
                    $record->permissions()->sync($permissionIds);
                }
                if ($resource === 'products' && $id && Money::compare((string) $before['price'], (string) $record->price) !== 0) {
                    ProductStockLayer::where('product_id', $record->id)->where('status', 'ACTIVE')->update(['selling_price' => $record->price]);
                }
                if ($resource === 'products' && ! $id && $record->track_stock) {
                    $record->load('unit');
                    $opening = $data['opening_layers'] ?? [['quantity' => $data['stock'], 'cost' => $data['cost'], 'selling_price' => $data['price']]];
                    foreach ($opening as $index => $row) {
                        if ($index > 0 && Money::compare((string) $row['quantity'], 0) <= 0) {
                            throw ValidationException::withMessages(['opening_layers.'.$index.'.quantity' => 'Additional stock rows need a quantity greater than zero.']);
                        }
                        if (Money::compare((string) $row['quantity'], 0) > 0) {
                            app(StockLayerService::class)->receive($record, (string) $row['quantity'], (string) $row['cost'], (string) $row['selling_price'], 'OPENING_STOCK', $record->sku, auth()->id(), expiresAt: app(ExpiryService::class)->incoming($record, $row)['expires_at'], batchNumber: app(ExpiryService::class)->incoming($record, $row)['batch_number']);
                        }
                    }
                }
                if ($resource === 'units' && ! app(DefaultUnitService::class)->current() && $record->active) {
                    app(DefaultUnitService::class)->set($record->id);
                }
                unset($before['password']);
                $after = $record->getAttributes();
                if (in_array($resource, ['products', 'unit-presets'])) {
                    $after['conversions'] = $record->conversions->toArray();
                }
                if ($resource === 'products') {
                    $after['categories'] = $categoryIds;
                }

                if ($resource === 'roles') {
                    $after['permissions'] = $permissionIds;
                }
                unset($after['password']);
                Audit::record($resource.'.save', $record, $before, $after);

                return $record;
            }, 3);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
            if ($resource === 'products' && $e instanceof UniqueConstraintViolationException && ! empty($data['barcode'])) {
                app(BarcodeService::class)->unique($data['barcode'], $id);
            }
            throw $e;
        }

        return $result;
    }

    public function delete(string $resource, int $id): void
    {
        $def = Resources::get($resource);
        DB::transaction(function () use ($resource, $def, $id) {
            $record = $def['model']::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->guardEditable($resource, $record);
            if ($resource === 'units' && $record->default_slot === 1) {
                throw ValidationException::withMessages(['delete' => 'Choose another default unit before deleting this one.']);
            }
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
