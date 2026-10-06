<?php

namespace App\Policies;

use App\Models\ChangeRequest;
use App\Models\Project;
use App\Models\User;

class ChangeRequestPolicy
{
    public function view(User $user, ChangeRequest $cr): bool
    {
        return $user->roleIn($cr->project->workspace) !== null;
    }

    public function create(User $user, Project $project): bool
    {
        return $user->roleIn($project->workspace)?->canContribute() ?? false;
    }

    // Push revisions, manage reviewers or close: the author, or a workspace manager, while it's open.
    public function revise(User $user, ChangeRequest $cr): bool
    {
        if (!$cr->isOpen()) {
            return false;
        }

        return $user->id === $cr->created_by
            || ($user->roleIn($cr->project->workspace)?->canManageWorkspace() ?? false);
    }

    public function close(User $user, ChangeRequest $cr): bool
    {
        return $this->revise($user, $cr);
    }

    // Approve or request changes: contributors, but never on your own change request.
    public function review(User $user, ChangeRequest $cr): bool
    {
        return $cr->isOpen()
            && $user->id !== $cr->created_by
            && ($user->roleIn($cr->project->workspace)?->canContribute() ?? false);
    }

    // Whether the Merge button appears. The service still checks the rules under a lock.
    public function merge(User $user, ChangeRequest $cr): bool
    {
        return $this->revise($user, $cr);
    }

    public function comment(User $user, ChangeRequest $cr): bool
    {
        return $user->roleIn($cr->project->workspace)?->canComment() ?? false;
    }

    // Start or reply to a thread: anyone who can comment, while it's open.
    public function discuss(User $user, ChangeRequest $cr): bool
    {
        return $cr->isOpen() && ($user->roleIn($cr->project->workspace)?->canComment() ?? false);
    }

    // Resolve or unresolve a thread: contributors, while it's open.
    public function resolve(User $user, ChangeRequest $cr): bool
    {
        return $cr->isOpen() && ($user->roleIn($cr->project->workspace)?->canContribute() ?? false);
    }

    // Only closed-without-merging requests can be reopened. Merged ones are history.
    public function reopen(User $user, ChangeRequest $cr): bool
    {
        if ($cr->status !== ChangeRequest::CLOSED || $cr->merged_at !== null) {
            return false;
        }

        return $user->id === $cr->created_by
            || ($user->roleIn($cr->project->workspace)?->canManageWorkspace() ?? false);
    }
}