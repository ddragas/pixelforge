<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Pixelforge;
use Tests\TestCase;

class SubmitGenerationTest extends TestCase
{
    use Pixelforge, RefreshDatabase;

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPixelforge();

        $this->generation = Generation::factory()->for(User::factory()->create(['balance' => 1_000_000_000]))->create();
    }

    public function test_it_sends_our_id_as_the_idempotency_key_and_as_metadata(): void
    {
        Http::fake(['*/v1/generations' => Http::response($this->remoteGeneration('queued'), 202)]);

        dispatch_sync(new SubmitGeneration($this->generation));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', $this->generation->id)
            && $request['metadata'] === ['generation_id' => $this->generation->id]
            && $request['prompt'] === $this->generation->prompt);

        $generation = $this->generation->refresh();

        $this->assertSame('gen_01TEST', $generation->provider_id);
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame($this->rates(), $generation->rates);
    }

    public function test_when_pixelforge_is_busy_it_waits_as_long_as_it_is_told_and_tries_again(): void
    {
        Queue::fake();
        Http::fake(['*/v1/generations' => Http::response(
            ['error' => ['type' => 'rate_limit_error', 'code' => 'concurrency_limit_exceeded', 'message' => 'Too many generations.']],
            429,
            ['Retry-After' => '5'],
        )]);

        $job = (new SubmitGeneration($this->generation))->withFakeQueueInteractions();
        app()->call($job->handle(...));

        $job->assertReleased(delay: 5);
        $this->assertSame(GenerationStatus::Pending, $this->generation->refresh()->status);
    }

    public function test_an_overloaded_answer_is_retried_too(): void
    {
        Http::fake(['*/v1/generations' => Http::response(
            ['error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Overloaded.']],
            503,
            ['Retry-After' => '2'],
        )]);

        $job = (new SubmitGeneration($this->generation))->withFakeQueueInteractions();
        app()->call($job->handle(...));

        $job->assertReleased();
        $this->assertSame(GenerationStatus::Pending, $this->generation->refresh()->status);
    }

    public function test_a_rejected_prompt_fails_at_once_costs_nothing_and_frees_the_credit(): void
    {
        Http::fake(['*/v1/generations' => Http::response(
            ['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'The prompt was rejected.']],
            400,
        )]);

        dispatch_sync(new SubmitGeneration($this->generation));

        $generation = $this->generation->refresh();

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertSame(0, $generation->charged);
        $this->assertSame(0, $generation->reserved);
        $this->assertSame(1_000_000_000, $generation->user->balance);
    }

    public function test_when_the_time_to_submit_runs_out_the_generation_fails_without_charge(): void
    {
        (new SubmitGeneration($this->generation))->failed(null);

        $generation = $this->generation->refresh();

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('provider_unavailable', $generation->error_code);
        $this->assertSame(0, $generation->reserved);
    }

    public function test_a_generation_that_was_already_accepted_is_not_submitted_again(): void
    {
        Http::fake();
        $this->generation->update(['status' => GenerationStatus::Queued, 'provider_id' => 'gen_01TEST']);

        dispatch_sync(new SubmitGeneration($this->generation));

        Http::assertNothingSent();
    }

    public function test_an_answer_that_arrives_after_the_webhook_does_not_move_the_generation_back(): void
    {
        // The "succeeded" webhook was handled while our create request was still in flight.
        $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded', [
            'metadata' => ['generation_id' => $this->generation->id],
        ]));

        resolve(GenerationLifecycle::class)->apply($this->remoteGeneration('queued', [
            'metadata' => ['generation_id' => $this->generation->id],
        ]));

        $this->assertSame(GenerationStatus::Succeeded, $this->generation->refresh()->status);
    }
}
