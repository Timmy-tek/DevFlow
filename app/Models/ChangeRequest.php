<?php

namespace App\Models;

use App\Support\ReviewSummary;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

#[Fillable(['title', 'description', 'status', 'task_id', 'sync_task', 'created_by', 'merged_by', 'merged_at', 'closed_at'])]
class ChangeRequest extends Model
{
    public const OPEN = 'open';
    public const MERGED = 'merged';
    public const CLOSED = 'closed';

    protected static function booted(): void
    {
        // Per-project number: CR-1, CR-2, ...
        static::creating(function (ChangeRequest $cr) {
            $cr->number ??= ((int) static::where('project_id', $cr->project_id)->max('number')) + 1;
        });
    }

    protected function casts(): array
    {
        return [
            'sync_task' => 'boolean',
            'merged_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function ref(): string
    {
        return 'CR-' . $this->number;
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /**
     * Label and Tailwind classes. For open requests this reflects the review state,
     * as long as the `reviews` and `latestRevision` relations were eager loaded.
     *
     * @return array{0: string, 1: string}
     */
    public function badge(): array
    {
        return match ($this->status) {
            self::MERGED => ['Merged', 'bg-lavender text-ink'],
            self::CLOSED => ['Closed', 'bg-zinc-200 text-ink dark:bg-white/15 dark:text-white'],
            default => $this->openBadge(),
        };
    }

    protected function openBadge(): array
    {
        if (!$this->relationLoaded('reviews')) {
            return ['Open', 'bg-butter text-ink'];
        }

        $verdicts = ReviewSummary::verdicts(
            $this->reviewRows($this->reviews),
            (int) $this->created_by,
            $this->latestRevision?->id,
        );

        if (in_array('changes_requested', $verdicts, true)) {
            return ['Changes requested', 'bg-peach text-ink'];
        }

        if (in_array('approved', $verdicts, true)) {
            return ['Approved', 'bg-mint text-ink'];
        }

        return ['In review', 'bg-butter text-ink'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function merger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CrRevision::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(CrComment::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(CrReview::class);
    }

    public function reviewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'cr_reviewers')->withTimestamps();
    }

    public function latestRevision(): HasOne
    {
        return $this->hasOne(CrRevision::class)->latestOfMany('number');
    }

    /**
     * The proposed state of each file: the most recent revision that touched it.
     * Keyed by project_file_id, later revisions overwrite earlier ones.
     */
    public function latestFiles(): Collection
    {
        return CrRevisionFile::query()
            ->whereIn('cr_revision_id', $this->revisions()->select('id'))
            ->with(['revision', 'file', 'baseVersion'])
            ->orderBy('cr_revision_id')
            ->get()
            ->keyBy('project_file_id');
    }

    /** Reviews, approvals, stale approvals and merge blockers, all in one object. */
    public function reviewSummary(): ReviewSummary
    {
        $latestRevisionId = $this->revisions()->max('id');
        $latestRevisionId = $latestRevisionId === null ? null : (int) $latestRevisionId;

        $reviews = $this->reviewRows($this->reviews()->orderBy('id')->get());
        $requestedIds = $this->reviewers()->pluck('users.id')->map(fn($id) => (int) $id)->all();

        $userIds = array_values(array_unique([...$requestedIds, ...array_column($reviews, 'user_id')]));
        $names = User::whereIn('id', $userIds)->pluck('name', 'id')->all();

        // A file is "out of date" when someone merged a newer version after this was proposed.
        $files = $this->latestFiles();
        $current = FileVersion::whereIn('project_file_id', $files->keys())
            ->selectRaw('project_file_id, max(number) as latest')
            ->groupBy('project_file_id')
            ->pluck('latest', 'project_file_id');

        $outOfDate = [];

        foreach ($files as $fileId => $rf) {
            $deleted = $rf->file?->trashed() ?? true;
            $now = (int) ($current[$fileId] ?? 0);

            if ($deleted || $now !== $rf->baseVersion->number) {
                $outOfDate[] = [
                    'name' => $rf->file?->name ?? $rf->original_name,
                    'base' => $rf->baseVersion->number,
                    'current' => $deleted ? null : $now,
                ];
            }
        }

        return ReviewSummary::build(
            $reviews,
            $requestedIds,
            $names,
            (int) $this->created_by,
            $latestRevisionId,
            $outOfDate,
            $files->isNotEmpty(),
            $this->isOpen(),
        );
    }

    public function threads(): HasMany
    {
        return $this->hasMany(CrThread::class);
    }

    /** @return array<int, array{id: int, user_id: int, state: string, cr_revision_id: int}> */
    protected function reviewRows(iterable $reviews): array
    {
        $rows = [];

        foreach ($reviews as $review) {
            $rows[] = [
                'id' => (int) $review->id,
                'user_id' => (int) $review->user_id,
                'state' => $review->state,
                'cr_revision_id' => (int) $review->cr_revision_id,
            ];
        }

        return $rows;
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }
}