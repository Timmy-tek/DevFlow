<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['change_request_id', 'user_id', 'project_file_id', 'cr_revision_file_id'])]
class CrFileView extends Model
{
    public const UPDATED_AT = null;
}