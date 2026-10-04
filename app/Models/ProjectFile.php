<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

#[Fillable(['name', 'created_by'])]
class ProjectFile extends Model
{
    use SoftDeletes;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FileVersion::class);
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(FileVersion::class)->latestOfMany('number');
    }

    /**
     * Stores the upload on the private disk and records it as the next version.
     * The stored name is random, so the user-supplied filename never touches the filesystem.
     */
    public function addVersion(UploadedFile $upload, ?int $userId, ?string $note = null, ?int $taskId = null): FileVersion
    {
        $number = ((int) $this->versions()->max('number')) + 1;

        $ext = strtolower(preg_replace('/[^A-Za-z0-9]/', '', pathinfo($upload->getClientOriginalName(), PATHINFO_EXTENSION)));
        $filename = 'v' . $number . '-' . Str::random(12) . ($ext !== '' ? '.' . substr($ext, 0, 10) : '');

        $sha256 = hash_file('sha256', $upload->getRealPath());
        $mime = $upload->getMimeType();
        $size = $upload->getSize();

        $path = $upload->storeAs("projects/{$this->project_id}/files/{$this->id}", $filename, 'local');

        return $this->versions()->create([
            'number' => $number,
            'original_name' => Str::limit($upload->getClientOriginalName(), 255, ''),
            'path' => $path,
            'mime' => $mime,
            'size' => $size,
            'sha256' => $sha256,
            'uploaded_by' => $userId,
            'note' => $note,
            'task_id' => $taskId,
        ]);
    }
}