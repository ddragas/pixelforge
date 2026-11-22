<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\Pixelforge;

uses(Pixelforge::class);

/*
 * Written after mutation testing showed that these details could be changed
 * without any test noticing.
 */

beforeEach(fn () => $this->setUpPixelforge());

it('waits longer after each refusal, up to a minute, and never less than Pixelforge asks', function (int $attempt, ?int $retryAfter, int $seconds): void {
    expect(SubmitGeneration::secondsToWait($attempt, $retryAfter))->toBe($seconds);
})->with([
    'first refusal' => [1, null, 2],
    'second' => [2, null, 4],
    'third' => [3, null, 8],
    'fifth' => [5, null, 32],
    'sixth is capped at a minute' => [6, null, 60],
    'so is every later one' => [7, null, 60],
    'and the fiftieth' => [50, null, 60],
    'Pixelforge asks for longer' => [1, 30, 30],
    'Pixelforge asks for longer than our cap' => [3, 120, 120],
    'Pixelforge asks for less than we would wait anyway' => [4, 1, 16],
    'Pixelforge asks for exactly what we would wait' => [3, 8, 8],
]);

it('sends Pixelforge exactly what the user asked for, with our id as the idempotency key', function (): void {
    Http::fake(['*/v1/generations' => Http::response($this->remoteGeneration('queued'), 202)]);
    $generation = Generation::factory()->for(User::factory()->create())->create([
        'prompt' => 'A lighthouse at dawn',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ]);

    dispatch_sync(new SubmitGeneration($generation));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->header('Idempotency-Key') === [$generation->id]
        && $request->data() === [
            'prompt' => 'A lighthouse at dawn',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
            'metadata' => ['generation_id' => $generation->id],
        ]);
});

it('raises the alarm when Pixelforge rejects our API key, and only then', function (string $code, int $status, bool $alarm): void {
    Log::spy();
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => $code, 'message' => 'No.']], $status)]);
    $generation = Generation::factory()->for(User::factory()->create())->create();

    dispatch_sync(new SubmitGeneration($generation));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed);
    $alarm ? Log::shouldHaveReceived('critical')->once() : Log::shouldNotHaveReceived('critical');
})->with([
    'a rejected key' => ['invalid_api_key', 401, true],
    'a rejected prompt' => ['content_policy_violation', 400, false],
]);

it('does not send a generation twice when the job runs again after it was accepted', function (): void {
    Http::fake();
    $generation = Generation::factory()->for(User::factory()->create())->queued()->create();

    dispatch_sync(new SubmitGeneration($generation));

    Http::assertNothingSent();
});

it('marks a webhook event as dealt with once it has been applied', function (): void {
    $generation = Generation::factory()->for(User::factory()->create(['balance' => 1_000_000_000]))->queued()->create();
    $event = WebhookEvent::create(['id' => 'evt_1', 'type' => 'generation.succeeded', 'payload' => [
        'id' => 'evt_1', 'data' => ['object' => $this->remoteGeneration('succeeded')],
    ]]);

    dispatch_sync(new ProcessWebhookEvent($event));

    expect($event->refresh()->processed_at)->not->toBeNull()
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('marks an event that is not about a generation as dealt with, and touches nothing', function (): void {
    $generation = Generation::factory()->for(User::factory()->create())->queued()->create();
    $event = WebhookEvent::create(['id' => 'evt_2', 'type' => 'ping', 'payload' => ['id' => 'evt_2', 'data' => ['object' => ['object' => 'ping']]]]);

    dispatch_sync(new ProcessWebhookEvent($event));

    expect($event->refresh()->processed_at)->not->toBeNull()
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});
