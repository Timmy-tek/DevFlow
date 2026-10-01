<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Developer = 'developer';
    case Designer = 'designer';
    case Client = 'client';
    case Viewer = 'viewer';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function canManageWorkspace(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canContribute(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Developer, self::Designer], true);
    }

    public function canComment(): bool
    {
        return $this->canContribute() || $this === self::Client;
    }
}