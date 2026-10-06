<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_file_id', 'cr_revision_file_id', 'kind', 'side', 'line', 'pin_x', 'pin_y', 'created_by', 'resolved_at', 'resolved_by'])]
class CrThread extends Model
{
    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'pin_x' => 'float',
            'pin_y' => 'float',
            'resolved_at' => 'datetime',
        ];
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(CrThreadComment::class)->orderBy('id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}