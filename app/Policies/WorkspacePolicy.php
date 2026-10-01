<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace) !== null;
    }

    public function manage(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace)?->canManageWorkspace() ?? false;
    }
}