<?php

namespace App\Policies;

use App\Models\PurchaseReturn;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\SalesVisibility;

class TransactionReturnPolicy
{
    public function view(User $user, SaleReturn|PurchaseReturn $return): bool
    {
        return $return instanceof SaleReturn ? (($user->hasPermission('sales_returns.view') || ($return->user_id === $user->id && $user->hasPermission('sales_returns.create'))) && SalesVisibility::canSee($return->sale, $user)) : ($user->hasPermission('purchase_returns.view') || ($return->user_id === $user->id && $user->hasPermission('purchase_returns.create')));
    }

    public function cancel(User $user, SaleReturn|PurchaseReturn $return): bool
    {
        return $return instanceof SaleReturn ? ($user->hasPermission('sales_returns.cancel') && SalesVisibility::canSee($return->sale, $user)) : $user->hasPermission('purchase_returns.cancel');
    }
}
