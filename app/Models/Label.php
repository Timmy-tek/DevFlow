<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'color'])]
class Label extends Model
{
    public const COLORS = ['violet', 'orange', 'emerald', 'sky', 'rose', 'amber'];

    public function dotClass(): string
    {
        return match ($this->color) {
            'orange' => 'bg-orange-500',
            'emerald' => 'bg-emerald-500',
            'sky' => 'bg-sky-500',
            'rose' => 'bg-rose-500',
            'amber' => 'bg-amber-500',
            default => 'bg-violet-500',
        };
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class);
    }
}