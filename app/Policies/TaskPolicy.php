<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        return $user->roleIn($task->project->workspace) !== null;
    }

    public function create(User $user, Project $project): bool
    {
        return $user->roleIn($project->workspace)?->canContribute() ?? false;
    }

    public function reorder(User $user, Project $project): bool
    {
        return $this->create($user, $project);
    }

    public function update(User $user, Task $task): bool
    {
        return $user->roleIn($task->project->workspace)?->canContribute() ?? false;
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    public function comment(User $user, Task $task): bool
    {
        return $user->roleIn($task->project->workspace)?->canComment() ?? false;
    }
}