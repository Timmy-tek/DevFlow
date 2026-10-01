<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Comment;

class CommentObserver
{
    public function created(Comment $comment): void
    {
        $task = $comment->task;
        $project = $task->project;

        ActivityLog::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'user_id' => $comment->user_id,
            'action' => 'comment.added',
            'properties' => [
                'ref' => $project->key . '-' . $task->number,
                'title' => $task->title,
            ],
        ]);
    }
}