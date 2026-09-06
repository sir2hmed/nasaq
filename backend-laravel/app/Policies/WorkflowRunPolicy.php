<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowRun;

class WorkflowRunPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WorkflowRun $run): bool
    {
        return $run->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function cancel(User $user, WorkflowRun $run): bool
    {
        return $run->user_id === $user->id;
    }

    public function decideApproval(User $user, WorkflowRun $run): bool
    {
        return $run->user_id === $user->id;
    }
}
