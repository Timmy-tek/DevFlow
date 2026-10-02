<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_file_id', 'base_version_id', 'path', 'original_name', 'mime', 'size', 'sha256'])]
class CrRevisionFile extends Model
{
    public $timestamps = false;

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CrRevision::class, 'cr_revision_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(ProjectFile::class, 'project_file_id')->withTrashed();
    }

    public function baseVersion(): BelongsTo
    {
        return $this->belongsTo(FileVersion::class, 'base_version_id');
    }

    public function isImage(): bool
    {
        return in_array($this->mime, FileVersion::PREVIEWABLE, true);
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