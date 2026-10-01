<?php

namespace App\Enums;

enum TaskPriority: string
{
    case Urgent = 'urgent';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    // Card tint on the board.
    public function tone(): string
    {
        return match ($this) {
            self::Urgent => 'rose',
            self::High => 'peach',
            self::Medium => 'butter',
            self::Low => 'mint',
        };
    }
}