<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    /** @return array{0: string, 1: string} label and Tailwind classes */
    public function badge(): array
    {
        return match ($this->status) {
            self::MERGED => ['Merged', 'bg-lavender text-ink'],
            self::CLOSED => ['Closed', 'bg-zinc-200 text-ink dark:bg-white/15 dark:text-white'],
            default => ['Open', 'bg-butter text-ink'],
        };
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

    public function revisions(): HasMany
    {
        return $this->hasMany(CrRevision::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(CrComment::class);
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
}