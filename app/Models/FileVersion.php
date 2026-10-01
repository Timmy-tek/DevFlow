<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['number', 'original_name', 'path', 'mime', 'size', 'sha256', 'uploaded_by', 'change_request_id', 'note'])]
class FileVersion extends Model
{
    public const UPDATED_AT = null;

    // SVG is deliberately excluded: it can carry scripts.
    public const PREVIEWABLE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function file(): BelongsTo
    {
        return $this->belongsTo(ProjectFile::class, 'project_file_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return in_array($this->mime, self::PREVIEWABLE, true);
    }

    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = $this->size > 0 ? min((int) floor(log($this->size, 1024)), 3) : 0;

        return round($this->size / (1024 ** $i), $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }

    public function shortHash(): string
    {
        return substr($this->sha256, 0, 8);
    }
}