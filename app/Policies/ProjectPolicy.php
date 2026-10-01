<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->currentWorkspace !== null;
    }

    public function view(User $user, Project $project): bool
    {
        return $user->roleIn($project->workspace) !== null;
    }

    public function create(User $user): bool
    {
        $workspace = $user->currentWorkspace;

        return $workspace && $user->roleIn($workspace)?->canManageWorkspace();
    }

    public function update(User $user, Project $project): bool
    {
        return $user->roleIn($project->workspace)?->canManageWorkspace() ?? false;
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->roleIn($project->workspace)?->canManageWorkspace() ?? false;
    }
}