<?php

namespace App\Enums;

enum ActivityStatus: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case FollowUp = 'follow_up';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Scheduled => 'Scheduled',
            self::FollowUp => 'For Follow-up',
            self::Completed => 'Completed',
        };
    }
}
