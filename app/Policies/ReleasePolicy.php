<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Release;
use App\Models\User;

class ReleasePolicy
{
    public function view(User $user, Release $release): bool
    {
        return $user->roleIn($release->project->workspace) !== null;
    }

    public function create(User $user, Project $project): bool
    {
        return $user->roleIn($project->workspace)?->canContribute() ?? false;
    }

    // Edit notes, version and contents: contributors, while it's still a draft.
    public function update(User $user, Release $release): bool
    {
        return !$release->isPublished()
            && ($user->roleIn($release->project->workspace)?->canContribute() ?? false);
    }

    // Publishing is a one-way door, so it's limited to workspace managers.
    public function publish(User $user, Release $release): bool
    {
        return !$release->isPublished()
            && ($user->roleIn($release->project->workspace)?->canManageWorkspace() ?? false);
    }

    public function delete(User $user, Release $release): bool
    {
        return !$release->isPublished()
            && ($user->id === $release->created_by
                || ($user->roleIn($release->project->workspace)?->canManageWorkspace() ?? false));
    }
}