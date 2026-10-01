<?php

namespace App\Policies;

use App\Models\Label;
use App\Models\User;

class LabelPolicy
{
    public function update(User $user, Label $label): bool
    {
        return $user->roleIn($label->workspace)?->canContribute() ?? false;
    }

    // Labels are shared across the workspace, so only managers can delete them.
    public function delete(User $user, Label $label): bool
    {
        return $user->roleIn($label->workspace)?->canManageWorkspace() ?? false;
    }
}