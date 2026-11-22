<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PixelforgeWebhookController extends Controller
{
    /**
     * Check the signature, remember the event, answer straight away.
     * The work itself happens in a queued job.
     */
    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();

        abort_unless($this->signedByPixelforge($payload, (string) $request->header('Pixelforge-Signature')), 400, 'Invalid Pixelforge signature.');

        $event = json_decode($payload, true);

        abort_unless(is_array($event) && isset($event['id'], $event['type']), 400, 'Not a Pixelforge event.');

        // The event id is the primary key. A second delivery of the same event
        // inserts nothing, so it is acknowledged and otherwise ignored.
        $firstTime = WebhookEvent::insertOrIgnore([
            'id' => $event['id'],
            'type' => $event['type'],
            'payload' => $payload,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;

        if ($firstTime) {
            dispatch(new ProcessWebhookEvent(WebhookEvent::query()->whereKey($event['id'])->firstOrFail()));
        }

        return response()->noContent();
    }

    /**
     * The header is "t=<unix time>,v1=<hex HMAC-SHA256 of 't.body'>". The raw
     * body is checked, before any decoding, and old timestamps are refused so
     * a captured request cannot be replayed later.
     */
    private function signedByPixelforge(string $payload, string $header): bool
    {
        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $secret = (string) config('pixelforge.webhook_secret');

        if ($secret === '' || abs(time() - $timestamp) > config('pixelforge.webhook_tolerance')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', "{$timestamp}.{$payload}", $secret), $parts['v1'] ?? '');
    }
}
