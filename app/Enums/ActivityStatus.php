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

    public static function requiresScheduledDate(mixed $status): bool
    {
        $value = $status instanceof self ? $status->value : $status;

        return in_array($value, [self::Scheduled->value, self::FollowUp->value], true);
    }
}
