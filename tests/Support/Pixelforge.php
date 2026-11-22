<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * Pixelforge as the tests see it: the documented pricing, generation
 * objects in any status, and correctly signed webhook requests.
 */
trait Pixelforge
{
    protected string $webhookSecret = 'whsec_test';

    protected function setUpPixelforge(): void
    {
        config(['pixelforge.api_key' => 'pf_test', 'pixelforge.webhook_secret' => $this->webhookSecret]);
    }

    /** @return array<string, mixed> exactly what GET /v1/pricing documents */
    protected function pricing(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => $this->rates(),
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    /** @return array<string, string> */
    protected function rates(): array
    {
        return [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];
    }

    protected function fakePricing(): void
    {
        Http::fake(['*/v1/pricing' => Http::response($this->pricing())]);
    }

    /**
     * A generation object as Pixelforge would return it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function remoteGeneration(string $status, array $overrides = []): array
    {
        $finished = in_array($status, ['succeeded', 'failed'], true);

        return array_merge([
            'id' => 'gen_01TEST',
            'object' => 'generation',
            'status' => $status,
            'prompt' => 'A lighthouse on a cliff at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'metadata' => [],
            'image_url' => $status === 'succeeded' ? 'https://pixelforge.promptedit.dev/images/gen_01TEST.png?signature=x' : null,
            'image_url_expires_at' => $status === 'succeeded' ? now()->addDay()->toIso8601ZuluString() : null,
            // 8 text + 1100 output + 1500 reasoning tokens: 40,000 + 44,000,000 + 18,750,000 nanodollars.
            'usage' => $status === 'succeeded' ? [
                'input_text_tokens' => 8,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 1500,
                'total_tokens' => 2608,
            ] : null,
            'rates' => $this->rates(),
            'error' => $status === 'failed' ? ['code' => 'internal_error', 'message' => 'Something went wrong on our side.'] : null,
            'created_at' => '2026-11-04T14:03:11Z',
            'started_at' => $status === 'queued' ? null : '2026-11-04T14:03:13Z',
            'completed_at' => $finished ? '2026-11-04T14:03:29Z' : null,
        ], $overrides);
    }

    /**
     * Deliver a webhook the way Pixelforge does: raw JSON body, signed.
     *
     * @param  array<string, mixed>  $generation
     */
    protected function deliverWebhook(string $eventId, string $type, array $generation, ?string $secret = null, ?int $timestamp = null)
    {
        $body = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-04T14:03:29Z',
            'data' => ['object' => $generation],
        ]);

        $timestamp ??= time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret ?? $this->webhookSecret);

        return $this->call('POST', '/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'HTTP_PIXELFORGE_EVENT_ID' => $eventId,
        ], content: $body);
    }
}
