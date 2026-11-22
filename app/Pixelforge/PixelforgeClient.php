<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The only place that talks HTTP to Pixelforge.
 */
class PixelforgeClient
{
    /**
     * Ask for a generation. The idempotency key makes a retry safe: the same
     * key with the same parameters returns the generation already created.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed> the generation object
     */
    public function createGeneration(array $parameters, string $idempotencyKey): array
    {
        return $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $parameters));
    }

    /** @return array<string, mixed> the generation object */
    public function generation(string $id): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get("generations/{$id}"));
    }

    /**
     * What Pixelforge billed this API key, per UTC day. The source of truth for provider costs.
     *
     * @return array<string, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    /**
     * Current prices and token rules. Remembered for a minute; if Pixelforge
     * cannot be reached, the last known prices are used so the form still works.
     */
    public function pricing(): Pricing
    {
        $fresh = Cache::get('pixelforge.pricing');

        if ($fresh === null) {
            try {
                $fresh = $this->send(fn (PendingRequest $http) => $http->get('pricing'));
                Cache::put('pixelforge.pricing', $fresh, now()->addMinute());
                Cache::forever('pixelforge.pricing.last_known', $fresh);
            } catch (PixelforgeException $exception) {
                $fresh = Cache::get('pixelforge.pricing.last_known') ?? throw $exception;
            }
        }

        return Pricing::fromResponse($fresh);
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     * @return array<string, mixed>
     */
    private function send(callable $request): array
    {
        try {
            $response = $request(
                Http::baseUrl(config('pixelforge.base_url'))
                    ->withToken((string) config('pixelforge.api_key'))
                    ->acceptJson()
                    ->asJson()
                    ->connectTimeout(5)
                    ->timeout(15)
            );
        } catch (ConnectionException $exception) {
            throw new PixelforgeException('connection_failed', $exception->getMessage());
        }

        if ($response->successful()) {
            return $response->json();
        }

        $retryAfter = $response->header('Retry-After');

        throw new PixelforgeException(
            errorCode: $response->json('error.code') ?? ($response->serverError() ? 'server_error' : 'unknown_error'),
            message: $response->json('error.message') ?? "Pixelforge answered with HTTP {$response->status()}.",
            status: $response->status(),
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }
}
