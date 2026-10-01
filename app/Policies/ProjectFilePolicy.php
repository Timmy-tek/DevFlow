<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\User;

class ProjectFilePolicy
{
    public function view(User $user, ProjectFile $file): bool
    {
        return $user->roleIn($file->project->workspace) !== null;
    }

    public function create(User $user, Project $project): bool
    {
        return $user->roleIn($project->workspace)?->canContribute() ?? false;
    }

    public function delete(User $user, ProjectFile $file): bool
    {
        return $user->roleIn($file->project->workspace)?->canManageWorkspace() ?? false;
    }
}