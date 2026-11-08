<?php

namespace App\Policies;

use App\Models\RepairJob;
use App\Models\User;

class RepairJobPolicy
{
    public function view(User $user, RepairJob $job): bool
    {
        return $user->hasPermission('repairs.view') && RepairJob::visibleTo($user)->whereKey($job->id)->exists();
    }

    public function act(User $user, RepairJob $job, string $action): bool
    {
        return $this->view($user, $job) && $user->hasPermission('repairs.'.$action);
    }
}
