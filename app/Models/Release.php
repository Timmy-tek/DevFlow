<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['version', 'title', 'notes', 'status', 'published_at', 'published_by', 'created_by'])]
class Release extends Model
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(ChangeRequest::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}