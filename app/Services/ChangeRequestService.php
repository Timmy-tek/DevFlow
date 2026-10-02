<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\ActivityLog;
use App\Models\ChangeRequest;
use App\Models\CrRevision;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChangeRequestService
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * @param  array<int, array{upload: \Illuminate\Http\UploadedFile, target: string|int}>  $staged
     * @return array<int, string> human-readable problems; empty means OK
     */
    public function problems(Project $project, array $staged): array
    {
        if ($staged === []) {
            return ['Add at least one file.'];
        }

        $problems = [];
        $seen = [];

        foreach ($staged as $item) {
            $upload = $item['upload'];
            $name = $upload->getClientOriginalName();
            $target = $item['target'] ?? '';

            if ($target === '' || $target === null) {
                $problems[] = "Choose which file {$name} replaces.";
                continue;
            }

            $file = $project->files()->find((int) $target);

            if (!$file) {
                $problems[] = "{$name}: the selected file no longer exists.";
                continue;
            }

            if (isset($seen[$file->id])) {
                $problems[] = "More than one upload replaces {$file->name}.";
                continue;
            }

            $seen[$file->id] = true;

            if ($upload->getSize() > self::MAX_BYTES) {
                $problems[] = "{$name} is over 10 MB.";
                continue;
            }

            $current = $file->latestVersion;

            if ($current && hash_file('sha256', $upload->getRealPath()) === $current->sha256) {
                $problems[] = "{$name} is identical to the current version of {$file->name}.";
            }
        }

        return $problems;
    }

    /** Stores the files and records a new revision. Call problems() first. */
    public function pushRevision(ChangeRequest $cr, array $staged, ?int $userId, ?string $note = null): CrRevision
    {
        $revision = DB::transaction(function () use ($cr, $staged, $userId, $note) {
            $revision = $cr->revisions()->create([
                'number' => ((int) $cr->revisions()->max('number')) + 1,
                'note' => $note,
                'created_by' => $userId,
            ]);

            foreach ($staged as $item) {
                $upload = $item['upload'];
                $file = $cr->project->files()->findOrFail((int) $item['target']);

                $ext = $this->extensionOf($upload->getClientOriginalName());
                $stored = $upload->storeAs(
                    "projects/{$cr->project_id}/change-requests/{$cr->id}/r{$revision->number}",
                    Str::random(16) . ($ext !== '' ? '.' . $ext : ''),
                    'local',
                );

                $revision->files()->create([
                    'project_file_id' => $file->id,
                    'base_version_id' => $file->latestVersion->id,
                    'path' => $stored,
                    'original_name' => Str::limit($upload->getClientOriginalName(), 255, ''),
                    'mime' => $upload->getMimeType(),
                    'size' => $upload->getSize(),
                    'sha256' => hash_file('sha256', $upload->getRealPath()),
                ]);
            }

            return $revision;
        });

        if ($revision->number > 1) {
            $this->log($cr, 'cr.revision', $userId, ['revision' => $revision->number]);
        }

        return $revision;
    }

    public function opened(ChangeRequest $cr, ?int $userId): void
    {
        $this->log($cr, 'cr.opened', $userId);

        $task = $cr->task;

        if ($task && $cr->sync_task && in_array($task->status, [TaskStatus::Todo, TaskStatus::InProgress], true)) {
            $this->moveTask($task, TaskStatus::Review);
        }
    }

    public function close(ChangeRequest $cr, ?int $userId): void
    {
        $cr->update(['status' => ChangeRequest::CLOSED, 'closed_at' => now()]);

        $task = $cr->task;

        if ($task && $cr->sync_task && $task->status === TaskStatus::Review) {
            $this->moveTask($task, TaskStatus::InProgress);
        }

        $this->log($cr, 'cr.closed', $userId);
    }

    public function log(ChangeRequest $cr, string $action, ?int $userId, array $extra = []): void
    {
        ActivityLog::create([
            'workspace_id' => $cr->project->workspace_id,
            'project_id' => $cr->project_id,
            'task_id' => $cr->task_id,
            'user_id' => $userId,
            'action' => $action,
            'properties' => ['ref' => $cr->ref(), 'title' => $cr->title, ...$extra],
        ]);
    }

    protected function moveTask(Task $task, TaskStatus $to): void
    {
        $max = $task->project->tasks()->where('status', $to->value)->max('position');

        // TaskObserver logs the move, so it shows up in the activity feed too.
        $task->update(['status' => $to, 'position' => is_null($max) ? 0 : $max + 1]);
    }

    protected function extensionOf(string $name): string
    {
        $ext = strtolower(preg_replace('/[^A-Za-z0-9]/', '', pathinfo($name, PATHINFO_EXTENSION)));

        return substr($ext, 0, 10);
    }
}