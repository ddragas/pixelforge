<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Repositories\WebhookEventRepository;
use App\Services\GenerationLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Acts on a webhook after it has been stored and answered, so Pixelforge
 * gets its reply within its ten seconds whatever the work takes.
 */
class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 5;

    public function __construct(public WebhookEvent $event) {}

    public function handle(GenerationLifecycle $lifecycle, WebhookEventRepository $events): void
    {
        $generation = $this->event->payload['data']['object'] ?? null;

        if (($generation['object'] ?? null) === 'generation') {
            $lifecycle->apply($generation);
        }

        $events->markProcessed($this->event);
    }
}
