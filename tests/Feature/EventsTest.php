<?php

declare(strict_types=1);

use App\Events\GenerationFailed;
use App\Events\GenerationSucceeded;
use App\Jobs\SubmitGeneration;
use App\Listeners\RaiseAlarmWhenApiKeyIsRejected;
use App\Listeners\RecordGenerationOutcome;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\Pixelforge;

uses(Pixelforge::class);

beforeEach(function (): void {
    $this->setUpPixelforge();
    $this->user = User::factory()->create(['balance' => 1_000_000_000]);
});

it('announces a success once, however many times Pixelforge says it', function (): void {
    Event::fake([GenerationSucceeded::class, GenerationFailed::class]);
    $generation = Generation::factory()->for($this->user)->queued()->create();

    $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded'));
    $this->deliverWebhook('evt_2', 'generation.succeeded', $this->remoteGeneration('succeeded'));
    $this->deliverWebhook('evt_3', 'generation.processing', $this->remoteGeneration('processing'));

    Event::assertDispatchedTimes(GenerationSucceeded::class, 1);
    Event::assertDispatched(GenerationSucceeded::class, fn (GenerationSucceeded $event): bool => $event->generation->is($generation) && $event->generation->charged === 78_487_500);
    Event::assertNotDispatched(GenerationFailed::class);
});

it('announces a failure once', function (): void {
    Event::fake([GenerationSucceeded::class, GenerationFailed::class]);
    $generation = Generation::factory()->for($this->user)->queued()->create();

    $this->deliverWebhook('evt_1', 'generation.failed', $this->remoteGeneration('failed'));
    $this->deliverWebhook('evt_2', 'generation.failed', $this->remoteGeneration('failed'));

    Event::assertDispatchedTimes(GenerationFailed::class, 1);
    Event::assertDispatched(GenerationFailed::class, fn (GenerationFailed $event): bool => $event->generation->is($generation) && $event->generation->error_code === 'internal_error');
    Event::assertNotDispatched(GenerationSucceeded::class);
});

it('announces a failure when Pixelforge never accepts the request', function (): void {
    Event::fake([GenerationFailed::class]);
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => 'content_policy_violation', 'message' => 'No.']], 400)]);

    dispatch_sync(new SubmitGeneration(Generation::factory()->for($this->user)->create()));

    Event::assertDispatchedTimes(GenerationFailed::class, 1);
});

it('announces nothing while a generation is only moving along', function (): void {
    Event::fake([GenerationSucceeded::class, GenerationFailed::class]);
    Generation::factory()->for($this->user)->queued()->create();

    $this->deliverWebhook('evt_1', 'generation.processing', $this->remoteGeneration('processing'));

    Event::assertNothingDispatched();
});

it('has the listeners wired to the events', function (): void {
    Event::fake();

    Event::assertListening(GenerationSucceeded::class, RecordGenerationOutcome::class);
    Event::assertListening(GenerationFailed::class, RecordGenerationOutcome::class);
    Event::assertListening(GenerationFailed::class, RaiseAlarmWhenApiKeyIsRejected::class);
});

it('logs the outcome with the figures an operator needs', function (): void {
    Log::spy();
    $generation = Generation::factory()->for($this->user)->queued()->create();

    $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded'));

    Log::shouldHaveReceived('info')->once()->with('generation.succeeded', [
        'generation' => $generation->id,
        'provider_id' => 'gen_01TEST',
        'user' => $this->user->id,
        'provider_cost' => 62_790_000,
        'charged' => 78_487_500,
        'error' => null,
    ]);
    Log::shouldNotHaveReceived('critical');
});

it('logs a failure with its reason', function (): void {
    Log::spy();
    $generation = Generation::factory()->for($this->user)->queued()->create();

    $this->deliverWebhook('evt_1', 'generation.failed', $this->remoteGeneration('failed'));

    Log::shouldHaveReceived('info')->once()->with('generation.failed', [
        'generation' => $generation->id,
        'provider_id' => 'gen_01TEST',
        'user' => $this->user->id,
        'provider_cost' => 0,
        'charged' => 0,
        'error' => 'internal_error',
    ]);
    Log::shouldNotHaveReceived('critical');
});
