<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'key', 'description', 'color', 'status', 'due_date', 'created_by'])]
class Project extends Model
{
    use SoftDeletes;

    public const COLORS = ['lavender', 'peach', 'mint', 'sky', 'rose', 'butter'];

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            $project->slug ??= static::uniqueSlug($project->workspace_id, $project->name);
            $project->key ??= static::makeKey($project->name);
        });
    }

    public static function uniqueSlug(int $workspaceId, string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $i = 2;

        while (static::withTrashed()->where('workspace_id', $workspaceId)->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'due_date' => 'date',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function makeKey(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);

        $key = count($words) > 1
            ? collect($words)->map(fn(string $word) => Str::substr($word, 0, 1))->implode('')
            : ($words[0] ?? '');

        $key = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $key));

        return Str::substr($key ?: 'PRJ', 0, 4);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProjectFile::class);
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(ChangeRequest::class);
    }
}