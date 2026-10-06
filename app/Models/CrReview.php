<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'cr_revision_id', 'state', 'body'])]
class CrReview extends Model
{
    public const UPDATED_AT = null;

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CrRevision::class, 'cr_revision_id');
    }
}