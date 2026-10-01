<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

#[Fillable(['email', 'role', 'token', 'invited_by', 'expires_at', 'accepted_at'])]
class WorkspaceInvitation extends Model
{
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}