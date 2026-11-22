<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\GenerationFailed;
use App\Events\GenerationSucceeded;
use Illuminate\Support\Facades\Log;

/**
 * Leaves a line in the log for every generation that ends, with the
 * figures an operator would want when a user asks "why was I charged this".
 */
class RecordGenerationOutcome
{
    public function handle(GenerationSucceeded|GenerationFailed $event): void
    {
        $generation = $event->generation;

        Log::info('generation.'.$generation->status->value, [
            'generation' => $generation->id,
            'provider_id' => $generation->provider_id,
            'user' => $generation->user_id,
            'provider_cost' => $generation->provider_cost,
            'charged' => $generation->charged,
            'error' => $generation->error_code,
        ]);
    }
}
