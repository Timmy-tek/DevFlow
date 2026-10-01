<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Observers\TaskObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'description', 'status', 'priority', 'position', 'due_date', 'assignee_id', 'created_by'])]
#[ObservedBy(TaskObserver::class)]
class Task extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        // Per-project number: DW-1, DW-2, ... The unique index guards against races.
        static::creating(function (Task $task) {
            $task->number ??= ((int) static::withTrashed()->where('project_id', $task->project_id)->max('number')) + 1;
        });
    }

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_date' => 'date',
        ];
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->status !== TaskStatus::Done
            && $this->due_date->endOfDay()->isPast();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}