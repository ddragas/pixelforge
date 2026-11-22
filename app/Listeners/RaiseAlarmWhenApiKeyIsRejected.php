<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\GenerationFailed;
use Illuminate\Support\Facades\Log;

/**
 * One user's rejected prompt is that user's problem. A rejected API key
 * stops every user, so it is logged at the level that pages someone.
 */
class RaiseAlarmWhenApiKeyIsRejected
{
    public function handle(GenerationFailed $event): void
    {
        if ($event->generation->error_code === 'invalid_api_key') {
            Log::critical('Pixelforge rejected our API key.');
        }
    }
}
