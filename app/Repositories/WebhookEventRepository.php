<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\WebhookEvent;

/**
 * Everything the application reads from or writes to the webhook_events table.
 */
class WebhookEventRepository
{
    /**
     * Store an event exactly as it arrived, unless we already have it.
     *
     * The event id is the primary key, so a second delivery of the same
     * event inserts nothing, even if both deliveries arrive at the same
     * moment. Returns the event the first time and null every time after.
     */
    public function recordOnce(string $id, string $type, string $payload): ?WebhookEvent
    {
        $inserted = WebhookEvent::query()->insertOrIgnore([
            'id' => $id,
            'type' => $type,
            'payload' => $payload,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $inserted === 1 ? WebhookEvent::query()->whereKey($id)->firstOrFail() : null;
    }

    public function markProcessed(WebhookEvent $event): void
    {
        $event->update(['processed_at' => now()]);
    }
}
