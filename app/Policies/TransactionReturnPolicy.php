<?php

namespace App\Policies;

use App\Models\PurchaseReturn;
use App\Models\SaleReturn;
use App\Models\User;

class TransactionReturnPolicy
{
    public function view(User $user, SaleReturn|PurchaseReturn $return): bool
    {
        return $return instanceof SaleReturn ? (($user->hasPermission('sales_returns.view') || ($return->user_id === $user->id && $user->hasPermission('sales_returns.create'))) && SaleReturn::visibleTo($user)->whereKey($return->id)->exists()) : ($user->hasPermission('purchase_returns.view') || ($return->user_id === $user->id && $user->hasPermission('purchase_returns.create')));
    }

    public function cancel(User $user, SaleReturn|PurchaseReturn $return): bool
    {
        return $return instanceof SaleReturn ? ($user->hasPermission('sales_returns.cancel') && SaleReturn::visibleTo($user)->whereKey($return->id)->exists()) : $user->hasPermission('purchase_returns.cancel');
    }
}
