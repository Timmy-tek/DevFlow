<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class FileUploadService
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Creates brand-new project files (v1), optionally tagged with the task they were made for.
     * Names that already exist are skipped: changes to existing files go through a change request.
     *
     * @param  iterable<\Illuminate\Http\UploadedFile>  $uploads
     * @return array{saved: int, problems: array<int, string>}
     */
    public function addNewFiles(Project $project, iterable $uploads, ?int $taskId, ?int $userId): array
    {
        $task = $taskId ? $project->tasks()->find($taskId) : null;
        $saved = 0;
        $problems = [];

        foreach ($uploads as $upload) {
            $name = $upload->getClientOriginalName();

            if ($upload->getSize() > self::MAX_BYTES) {
                $problems[] = "{$name} is over 10 MB.";
                continue;
            }

            $exists = $project->files()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();

            if ($exists) {
                $problems[] = "{$name} already exists. Changes to existing files go through a change request.";
                continue;
            }

            DB::transaction(function () use ($project, $upload, $name, $task, $userId) {
                $file = $project->files()->create([
                    'name' => mb_substr($name, 0, 255),
                    'created_by' => $userId,
                ]);

                $file->addVersion($upload, $userId, null, $task?->id);

                ActivityLog::create([
                    'workspace_id' => $project->workspace_id,
                    'project_id' => $project->id,
                    'task_id' => $task?->id,
                    'user_id' => $userId,
                    'action' => 'file.uploaded',
                    'properties' => [
                        'name' => $file->name,
                        'version' => 1,
                        'task' => $task ? $project->key . '-' . $task->number : null,
                    ],
                ]);
            });

            $saved++;
        }

        return ['saved' => $saved, 'problems' => $problems];
    }
}