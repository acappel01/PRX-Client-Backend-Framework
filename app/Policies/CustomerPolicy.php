<?php

namespace App\Policies;

use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Customer');
    }

    public function view(User $user): bool
    {
        return $user->can('View:Customer');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:Customer');
    }

    public function update(User $user): bool
    {
        return $user->can('Update:Customer');
    }
}
