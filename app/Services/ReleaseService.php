<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ChangeRequest;
use App\Models\FileVersion;
use App\Models\Project;
use App\Models\Release;
use App\Support\ReleaseNotes;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReleaseService
{
    /** Merged change requests that haven't shipped in any release yet. */
    public function unreleased(Project $project)
    {
        return $project->changeRequests()
            ->where('status', ChangeRequest::MERGED)
            ->whereNull('release_id')
            ->with(['task.labels', 'author'])
            ->orderBy('number')
            ->get();
    }

    public function createDraft(Project $project, string $version, ?string $title, array $changeRequestIds, int $userId): Release
    {
        return DB::transaction(function () use ($project, $version, $title, $changeRequestIds, $userId) {
            $release = $project->releases()->create([
                'version' => $version,
                'title' => $title ?: null,
                'created_by' => $userId,
            ]);

            // Only merged, still-unreleased requests from this project can be picked up.
            ChangeRequest::where('project_id', $project->id)
                ->where('status', ChangeRequest::MERGED)
                ->whereNull('release_id')
                ->whereIn('id', $changeRequestIds)
                ->update(['release_id' => $release->id]);

            $release->update(['notes' => $this->generateNotes($release)]);

            return $release;
        });
    }

    /** Markdown notes grouped by the first (A to Z) label on each change request's task. */
    public function generateNotes(Release $release): string
    {
        $crs = $release->changeRequests()->with(['task.labels', 'author', 'project'])->orderBy('number')->get();

        $entries = $crs->map(fn(ChangeRequest $cr) => [
            'title' => $cr->title,
            'ref' => $cr->ref(),
            'task_ref' => $cr->task ? $cr->project->key . '-' . $cr->task->number : null,
            'label' => $cr->task?->labels->sortBy('name')->first()?->name,
        ])->all();

        $contributors = $crs->pluck('author.name')->filter()->unique()->values()->all();

        return ReleaseNotes::build($entries, $this->filesFor($release), $contributors);
    }

    /** @return array<int, array{name: string, versions: array<int, string>}> */
    public function filesFor(Release $release): array
    {
        $versions = FileVersion::whereIn('change_request_id', $release->changeRequests()->select('id'))
            ->with('file')
            ->orderBy('number')
            ->get();

        $byFile = [];

        foreach ($versions as $version) {
            if (!$version->file) {
                continue;
            }

            $byFile[$version->project_file_id]['name'] = $version->file->name;
            $byFile[$version->project_file_id]['versions'][] = 'v' . $version->number;
        }

        usort($byFile, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return array_values($byFile);
    }

    /** @throws RuntimeException when the release can't be published */
    public function publish(Release $release, int $userId): void
    {
        DB::transaction(function () use ($release, $userId) {
            $locked = Release::whereKey($release->id)->lockForUpdate()->firstOrFail();

            if ($locked->isPublished()) {
                throw new RuntimeException('This release is already published.');
            }

            if (!$locked->changeRequests()->exists()) {
                throw new RuntimeException('Add at least one change request before publishing.');
            }

            if (trim((string) $locked->notes) === '') {
                throw new RuntimeException('Write or generate release notes before publishing.');
            }

            $locked->update([
                'status' => Release::PUBLISHED,
                'published_at' => now(),
                'published_by' => $userId,
            ]);

            ActivityLog::create([
                'workspace_id' => $locked->project->workspace_id,
                'project_id' => $locked->project_id,
                'user_id' => $userId,
                'action' => 'release.published',
                'properties' => ['version' => $locked->version, 'title' => $locked->title],
            ]);
        });
    }
}