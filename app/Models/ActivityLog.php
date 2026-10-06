<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'project_id', 'task_id', 'user_id', 'action', 'properties'])]
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function describe(): string
    {
        $p = $this->properties ?? [];
        $ref = $p['ref'] ?? '';
        $title = isset($p['title']) ? " “{$p['title']}”" : '';

        return match ($this->action) {
            'task.created' => "created {$ref}{$title}",
            'task.moved' => "moved {$ref} from {$p['from']} to {$p['to']}",
            'task.assigned' => !empty($p['assignee']) ? "assigned {$ref} to {$p['assignee']}" : "unassigned {$ref}",
            'task.deleted' => "deleted {$ref}{$title}",
            'comment.added' => "commented on {$ref}",

            'file.uploaded' => "uploaded {$p['name']} (v{$p['version']})" . (!empty($p['task']) ? " for {$p['task']}" : ''),
            'file.deleted' => "deleted file {$p['name']}",
            'cr.opened' => "opened {$ref}{$title}",
            'cr.revision' => "pushed r{$p['revision']} to {$ref}",
            'cr.comment' => "commented on {$ref}",
            'cr.closed' => "closed {$ref}",

            'cr.approved' => "approved {$ref}",
            'cr.changes_requested' => "requested changes on {$ref}",
            'cr.merged' => "merged {$ref}{$title}",

            'cr.reopened' => "reopened {$ref}{$title}",

            'release.published' => "published release {$p['version']}",
            default => $this->action,
        };
    }
}