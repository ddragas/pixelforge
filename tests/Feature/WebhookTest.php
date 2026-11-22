<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Pixelforge;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use Pixelforge, RefreshDatabase;

    private User $user;

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPixelforge();

        $this->user = User::factory()->create(['balance' => 1_000_000_000]);
        $this->generation = Generation::factory()->for($this->user)->queued()->create();
    }

    public function test_a_finished_generation_is_charged_at_exactly_cost_plus_a_quarter(): void
    {
        $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded'))->assertNoContent();

        $generation = $this->generation->refresh();

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(62_790_000, $generation->provider_cost);
        $this->assertSame(78_487_500, $generation->charged);
        $this->assertSame(0, $generation->reserved);
        $this->assertSame(1_000_000_000 - 78_487_500, $this->user->refresh()->balance);
        $this->assertNotNull($generation->image_url);
    }

    public function test_the_same_event_delivered_twice_is_recorded_once(): void
    {
        $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded'))->assertNoContent();
        $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded'))->assertNoContent();

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1, CreditTransaction::where('generation_id', $this->generation->id)->count());
        $this->assertSame(1_000_000_000 - 78_487_500, $this->user->refresh()->balance);
    }

    public function test_a_second_event_about_a_finished_generation_does_not_charge_again(): void
    {
        $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded'));
        // A different event id, so it is not caught by the duplicate check: the status rule has to stop it.
        $this->deliverWebhook('evt_2', 'generation.succeeded', $this->remoteGeneration('succeeded'));

        $this->assertSame(1, CreditTransaction::where('generation_id', $this->generation->id)->count());
        $this->assertSame(1_000_000_000 - 78_487_500, $this->user->refresh()->balance);
    }

    public function test_events_out_of_order_never_move_a_generation_backwards(): void
    {
        $this->deliverWebhook('evt_2', 'generation.succeeded', $this->remoteGeneration('succeeded'));
        $this->deliverWebhook('evt_1', 'generation.processing', $this->remoteGeneration('processing'));

        $generation = $this->generation->refresh();

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(78_487_500, $generation->charged);
    }

    public function test_a_failed_generation_costs_nothing_and_frees_the_credit_set_aside(): void
    {
        $this->deliverWebhook('evt_1', 'generation.failed', $this->remoteGeneration('failed'));

        $generation = $this->generation->refresh();

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame(0, $generation->charged);
        $this->assertSame(0, $generation->reserved);
        $this->assertSame('internal_error', $generation->error_code);
        $this->assertSame(1_000_000_000, $this->user->refresh()->balance);
        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_a_failure_after_success_does_not_refund_or_undo_anything(): void
    {
        $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded'));
        $this->deliverWebhook('evt_2', 'generation.failed', $this->remoteGeneration('failed'));

        $this->assertSame(GenerationStatus::Succeeded, $this->generation->refresh()->status);
        $this->assertSame(1_000_000_000 - 78_487_500, $this->user->refresh()->balance);
    }

    public function test_a_webhook_that_arrives_before_our_own_request_returned_is_matched_by_our_id(): void
    {
        $pending = Generation::factory()->for($this->user)->create();

        $this->deliverWebhook('evt_1', 'generation.processing', $this->remoteGeneration('processing', [
            'id' => 'gen_02EARLY',
            'metadata' => ['generation_id' => $pending->id],
        ]));

        $pending->refresh();

        $this->assertSame('gen_02EARLY', $pending->provider_id);
        $this->assertSame(GenerationStatus::Processing, $pending->status);
    }

    public function test_a_wrong_signature_is_refused_and_nothing_is_stored(): void
    {
        $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded'), secret: 'whsec_wrong')->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame(GenerationStatus::Queued, $this->generation->refresh()->status);
    }

    public function test_an_old_signature_is_refused_so_a_captured_request_cannot_be_replayed(): void
    {
        $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded'), timestamp: time() - 301)->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_an_event_about_a_generation_that_is_not_ours_is_acknowledged_and_ignored(): void
    {
        $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded', ['id' => 'gen_someone_elses']))->assertNoContent();

        $this->assertSame(GenerationStatus::Queued, $this->generation->refresh()->status);
    }

    public function test_the_webhook_is_reachable_without_a_csrf_token(): void
    {
        // Tests normally run with CSRF checking off, which would hide a webhook that browsers' rules block.
        $this->withMiddleware();

        $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded'))->assertNoContent();
    }

    public function test_the_dashboard_test_event_is_acknowledged(): void
    {
        $body = '{"id":"evt_ping","object":"event","type":"ping","created_at":"2026-11-04T14:03:29Z","data":{}}';
        $timestamp = time();

        $this->call('POST', '/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $this->webhookSecret),
        ], content: $body)->assertNoContent();
    }
}
