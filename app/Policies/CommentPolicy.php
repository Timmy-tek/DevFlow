<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    // Your own comments, or anything if you manage the workspace.
    public function delete(User $user, Comment $comment): bool
    {
        $role = $user->roleIn($comment->task->project->workspace);

        return $comment->user_id === $user->id || ($role?->canManageWorkspace() ?? false);
    }
}