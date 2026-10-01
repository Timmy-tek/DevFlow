<?php

namespace App\Observers;

use App\Enums\TaskStatus;
use App\Models\ActivityLog;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;

class TaskObserver
{
    public function created(Task $task): void
    {
        $this->log($task, 'task.created');
    }

    public function updated(Task $task): void
    {
        if ($task->wasChanged('status')) {
            $from = $task->getOriginal('status');
            $from = $from instanceof TaskStatus ? $from : TaskStatus::from($from);

            $this->log($task, 'task.moved', [
                'from' => $from->label(),
                'to' => $task->status->label(),
            ]);
        }

        if ($task->wasChanged('assignee_id')) {
            $this->log($task, 'task.assigned', [
                'assignee' => $task->assignee?->name,
            ]);
        }
    }

    public function deleted(Task $task): void
    {
        $this->log($task, 'task.deleted');
    }

    protected function log(Task $task, string $action, array $extra = []): void
    {
        $project = $task->project;

        ActivityLog::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'user_id' => Auth::id(),
            'action' => $action,
            'properties' => [
                'ref' => $project->key . '-' . $task->number,
                'title' => $task->title,
                ...$extra,
            ],
        ]);
    }
}