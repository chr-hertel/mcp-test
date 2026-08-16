<?php

declare(strict_types=1);

namespace App\Enum;

enum TalkStatus: string
{
    /** Accepted but not on the schedule yet. */
    case Accepted = 'accepted';

    /** Accepted and assigned to a room and time slot. */
    case Scheduled = 'scheduled';

    /** Was scheduled, then pulled — kept for the audit trail. */
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
