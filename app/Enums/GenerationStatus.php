<?php

declare(strict_types=1);

namespace App\Enums;

enum GenerationStatus: string
{
    /** Ours only: saved here, not yet accepted by Pixelforge. */
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * How far along a generation is. News about a generation is only applied
     * when it moves it forward, because Pixelforge delivers events in any order.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
