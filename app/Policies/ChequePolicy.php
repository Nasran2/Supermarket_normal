<?php

namespace App\Policies;

use App\Models\Cheque;
use App\Models\User;
use App\Support\SalesVisibility;

class ChequePolicy
{
    private function allowed(User $user, Cheque $cheque, string $action): bool
    {
        if (! $user->hasPermission('cheques.'.$action)) {
            return false;
        }
        if ($cheque->sale_id) {
            return SalesVisibility::canSee($cheque->sale, $user);
        }

        return ! $cheque->origin_customer_id || SalesVisibility::allowsOwner($cheque->created_by, $user);
    }

    public function view(User $user, Cheque $cheque): bool
    {
        return $this->allowed($user, $cheque, 'view');
    }

    public function edit(User $user, Cheque $cheque): bool
    {
        return $this->allowed($user, $cheque, 'edit');
    }

    public function resolveReturn(User $user, Cheque $cheque): bool
    {
        return $this->allowed($user, $cheque, 'resolve_return');
    }

    public function pass(User $user, Cheque $cheque): bool
    {
        return $this->allowed($user, $cheque, 'pass');
    }

    public function return(User $user, Cheque $cheque): bool
    {
        return $this->allowed($user, $cheque, 'return');
    }

    public function cancel(User $user, Cheque $cheque): bool
    {
        return $this->allowed($user, $cheque, 'cancel');
    }

    public function transfer(User $user, Cheque $cheque): bool
    {
        return $this->allowed($user, $cheque, 'transfer');
    }

    public function reverseTransfer(User $user, Cheque $cheque): bool
    {
        return $this->allowed($user, $cheque, 'reverse_transfer');
    }
}
