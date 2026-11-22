<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Support\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Pixelforge;

uses(Pixelforge::class);

beforeEach(function (): void {
    $this->setUpPixelforge();
    $this->user = User::factory()->create(['balance' => 1_000_000_000]);
});

describe('what is recorded when a generation ends', function (): void {
    it('keeps everything Pixelforge reported about a success, and charges for it once', function (): void {
        $generation = Generation::factory()->for($this->user)->queued()->create();
        $remote = $this->remoteGeneration('succeeded');

        $this->deliverWebhook('evt_1', 'generation.succeeded', $remote)->assertNoContent();

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->usage)->toBe($remote['usage'])
            ->and($generation->provider_cost)->toBe(62_790_000) // 40,000 + 44,000,000 + 18,750,000
            ->and($generation->charged)->toBe(78_487_500)       // x 1.25
            ->and($generation->reserved)->toBe(0)
            ->and($generation->image_url)->toBe($remote['image_url'])
            ->and($generation->image_url_expires_at->toIso8601ZuluString())->toBe($remote['image_url_expires_at'])
            ->and($generation->started_at->toIso8601ZuluString())->toBe('2026-11-04T14:03:13Z')
            ->and($generation->completed_at->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z')
            ->and($generation->error_code)->toBeNull()
            ->and($this->user->refresh()->balance)->toBe(1_000_000_000 - 78_487_500);
    });

    it('keeps the reason for a failure, charges nothing and frees the credit', function (): void {
        $generation = Generation::factory()->for($this->user)->queued()->create();

        $this->deliverWebhook('evt_1', 'generation.failed', $this->remoteGeneration('failed'))->assertNoContent();

        $generation->refresh();
        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->error_code)->toBe('internal_error')
            ->and($generation->error_message)->toBe('Something went wrong on our side.')
            ->and($generation->provider_cost)->toBe(0)
            ->and($generation->charged)->toBe(0)
            ->and($generation->reserved)->toBe(0)
            ->and($generation->completed_at->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z')
            ->and($this->user->refresh()->balance)->toBe(1_000_000_000);
    });

    it('copes with a failure that comes with no reason and no time', function (): void {
        $this->freezeSecond();
        $generation = Generation::factory()->for($this->user)->queued()->create();

        $this->deliverWebhook('evt_1', 'generation.failed', $this->remoteGeneration('failed', ['error' => null, 'completed_at' => null]))->assertNoContent();

        $generation->refresh();
        expect($generation->error_code)->toBe('generation_failed')
            ->and($generation->error_message)->toBe('The generation failed.')
            ->and($generation->completed_at->getTimestamp())->toBe(now()->getTimestamp());
    });

    it('uses the time it heard of a success when Pixelforge gives none', function (): void {
        $this->freezeSecond();
        $generation = Generation::factory()->for($this->user)->queued()->create();

        $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded', ['completed_at' => null, 'image_url_expires_at' => null]))->assertNoContent();

        $generation->refresh();
        expect($generation->completed_at->getTimestamp())->toBe(now()->getTimestamp())
            ->and($generation->image_url_expires_at)->toBeNull();
    });

    it('notes when work started as soon as it hears of it', function (): void {
        $generation = Generation::factory()->for($this->user)->queued()->create();

        $this->deliverWebhook('evt_1', 'generation.processing', $this->remoteGeneration('processing'))->assertNoContent();

        expect($generation->refresh())->status->toBe(GenerationStatus::Processing)
            ->and($generation->started_at->toIso8601ZuluString())->toBe('2026-11-04T14:03:13Z')
            ->and($generation->completed_at)->toBeNull()
            ->and($generation->reserved)->toBe(86_250_000);
    });
});

describe('the webhook door', function (): void {
    function signed(string $body, int $timestamp, string $secret = 'whsec_test', string $separator = ','): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp}{$separator}v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret),
        ];
    }

    it('answers 400, not 500, to a correctly signed body that is not an event', function (string $body): void {
        $this->call('POST', '/webhooks/pixelforge', server: signed($body, time()), content: $body)->assertStatus(400);

        expect(WebhookEvent::count())->toBe(0);
    })->with([
        'a bare string' => ['"hello"'],
        'not JSON at all' => ['hello'],
        'an event with no id' => ['{"type":"generation.succeeded"}'],
        'an event with no type' => ['{"id":"evt_1"}'],
        'an empty object' => ['{}'],
    ]);

    it('accepts a signature header written with spaces after the comma', function (): void {
        Queue::fake();
        $body = json_encode(['id' => 'evt_1', 'type' => 'generation.queued', 'data' => ['object' => $this->remoteGeneration('queued')]]);

        $this->call('POST', '/webhooks/pixelforge', server: signed($body, time(), separator: ' , '), content: $body)->assertNoContent();
    });

    it('accepts a delivery exactly as old as it is allowed to be, and not a second older', function (): void {
        $this->freezeSecond();
        Queue::fake();
        $tolerance = (int) config('pixelforge.webhook_tolerance');

        $this->deliverWebhook('evt_1', 'generation.queued', $this->remoteGeneration('queued'), timestamp: now()->getTimestamp() - $tolerance)->assertNoContent();
        $this->deliverWebhook('evt_2', 'generation.queued', $this->remoteGeneration('queued'), timestamp: now()->getTimestamp() - $tolerance - 1)->assertStatus(400);
        $this->deliverWebhook('evt_3', 'generation.queued', $this->remoteGeneration('queued'), timestamp: now()->getTimestamp() + $tolerance)->assertNoContent();
        $this->deliverWebhook('evt_4', 'generation.queued', $this->remoteGeneration('queued'), timestamp: now()->getTimestamp() + $tolerance + 1)->assertStatus(400);
    });

    it('keeps the event exactly as it arrived, with the time it arrived', function (): void {
        $this->freezeSecond();
        Queue::fake();
        $remote = $this->remoteGeneration('queued');

        $this->deliverWebhook('evt_1', 'generation.queued', $remote)->assertNoContent();

        $event = WebhookEvent::sole();
        expect($event->id)->toBe('evt_1')
            ->and($event->type)->toBe('generation.queued')
            ->and($event->payload['data']['object'])->toBe($remote)
            ->and($event->created_at->getTimestamp())->toBe(now()->getTimestamp())
            ->and($event->updated_at->getTimestamp())->toBe(now()->getTimestamp())
            ->and($event->processed_at)->toBeNull();
    });
});

describe('what the pages are given', function (): void {
    it('gives the history page every field of a finished generation, with money as exact text', function (): void {
        $generation = Generation::factory()->for($this->user)->queued()->create([
            'status' => GenerationStatus::Succeeded,
            'reference_images' => ['https://example.com/a.png'],
            'provider_cost' => 62_790_000,
            'charged' => 78_487_500,
            'reserved' => 0,
            'image_url' => 'https://images.example/one.png',
            'image_url_expires_at' => now()->addHour(),
            'completed_at' => now()->subMinute(),
        ])->refresh();

        $props = $this->actingAs($this->user)->get('/history')->assertOk()->inertiaPage()['props'];

        expect($props['generations'])->toHaveCount(1)
            ->and($props['generations'][0])->toEqual([
                'id' => $generation->id,
                'prompt' => 'A lighthouse on a cliff at dawn',
                'size' => '1024x1024',
                'quality' => 'medium',
                'reference_images' => ['https://example.com/a.png'],
                'status' => 'succeeded',
                'error_message' => null,
                'estimate' => ['lowest' => '0.056250000', 'expected' => '0.068750000', 'highest' => '0.086250000'],
                'charged' => '0.078487500',
                'image_url' => 'https://images.example/one.png',
                'image_expired' => false,
                'created_at' => $generation->created_at->toIso8601String(),
                'completed_at' => $generation->completed_at->toIso8601String(),
            ])
            ->and($props['running'])->toBeFalse()
            ->and($props['pagination'])->toBe(['current' => 1, 'last' => 1, 'total' => 1]);
    });

    it('withholds an image link that has expired and says that it has', function (): void {
        Generation::factory()->for($this->user)->queued()->create([
            'status' => GenerationStatus::Succeeded,
            'charged' => 78_487_500,
            'image_url' => 'https://images.example/old.png',
            'image_url_expires_at' => now()->subMinute(),
            'completed_at' => now()->subDay(),
        ]);

        $shown = $this->actingAs($this->user)->get('/history')->inertiaPage()['props']['generations'][0];

        expect($shown['image_url'])->toBeNull()->and($shown['image_expired'])->toBeTrue();
    });

    it('shows a running generation as running: no price, no image, nothing expired', function (): void {
        Generation::factory()->for($this->user)->queued()->create();

        $props = $this->actingAs($this->user)->get('/history')->inertiaPage()['props'];

        expect($props['generations'][0])->charged->toBeNull()->image_url->toBeNull()->image_expired->toBeFalse()->completed_at->toBeNull()->status->toBe('queued')
            ->and($props['running'])->toBeTrue();
    });

    it('shows twelve generations a page, newest first', function (): void {
        foreach (range(1, 13) as $minute) {
            Generation::factory()->for($this->user)->create(['prompt' => "Image {$minute}", 'created_at' => now()->subHour()->addMinutes($minute)]);
        }

        $first = $this->actingAs($this->user)->get('/history')->inertiaPage()['props'];
        $second = $this->actingAs($this->user)->get('/history?page=2')->inertiaPage()['props'];

        expect($first['generations'])->toHaveCount(12)
            ->and($first['generations'][0]['prompt'])->toBe('Image 13')
            ->and($first['pagination'])->toBe(['current' => 1, 'last' => 2, 'total' => 13])
            ->and($second['generations'])->toHaveCount(1)
            ->and($second['generations'][0]['prompt'])->toBe('Image 1')
            ->and($second['pagination'])->toBe(['current' => 2, 'last' => 2, 'total' => 13]);
    });

    it('shows the three most recent generations beside the form', function (): void {
        foreach (range(1, 4) as $minute) {
            Generation::factory()->for($this->user)->create(['prompt' => "Image {$minute}", 'created_at' => now()->subHour()->addMinutes($minute)]);
        }
        $this->fakePricing();

        $recent = $this->actingAs($this->user)->get('/generate')->inertiaPage()['props']['recent'];

        expect(array_column($recent, 'prompt'))->toBe(['Image 4', 'Image 3', 'Image 2']);
    });
});

describe('exactly enough credit', function (): void {
    $request = ['prompt' => 'A red fox in fresh snow', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => []];

    it('lets a user with exactly the highest price submit, and one nanodollar less not', function () use ($request): void {
        $this->fakePricing();
        Queue::fake();
        $highest = Money::fromDecimal($this->actingAs($this->user)->postJson('/generations/estimate', $request)->json('highest'));

        $this->user->forceFill(['balance' => $highest - 1])->save();
        $this->actingAs($this->user)->postJson('/generations/estimate', $request)->assertJsonPath('affordable', false);
        $this->actingAs($this->user)->post('/generations', $request)->assertSessionHasErrors([
            'credit' => 'You need at least $'.Money::toDecimal($highest).' of free credit for this image. Buy credits to continue.',
        ]);
        expect(Generation::count())->toBe(0);

        $this->user->forceFill(['balance' => $highest])->save();
        $this->actingAs($this->user)->postJson('/generations/estimate', $request)->assertJsonPath('affordable', true);
        $this->actingAs($this->user)->post('/generations', $request)->assertSessionHasNoErrors();
        expect(Generation::sole())->reserved->toBe($highest)->estimated_highest->toBe($highest);
    });
});

describe('the safety net', function (): void {
    it('asks about queued and processing generations alike, fifteen at most in one run', function (): void {
        Http::fake(['*/v1/generations/*' => fn ($request) => Http::response($this->remoteGeneration('processing', ['id' => basename($request->url())]))]);

        foreach (range(1, 16) as $number) {
            Generation::factory()->for($this->user)->queued("gen_{$number}")->create([
                'status' => $number % 2 === 0 ? GenerationStatus::Queued : GenerationStatus::Processing,
                'checked_at' => now()->subMinutes(10 + $number),
            ]);
        }

        $this->artisan('pixelforge:reconcile')->expectsOutput('Checked 15 generation(s).')->assertSuccessful();

        Http::assertSentCount(15);
        // The most recently checked one is the one left for the next run.
        expect(Generation::where('provider_id', 'gen_1')->sole()->checked_at->isBefore(now()->subMinutes(5)))->toBeTrue();
    });
});

describe('rate limits', function (): void {
    it('counts each user separately', function (): void {
        Queue::fake();
        $this->fakePricing();
        $request = ['prompt' => 'A red fox', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []];
        $busy = User::factory()->create(['balance' => 500_000_000_000]);

        foreach (range(1, 20) as $ignored) {
            $this->actingAs($busy)->post('/generations', $request);
        }

        $this->actingAs($busy)->post('/generations', $request)->assertTooManyRequests();
        $this->actingAs($this->user)->post('/generations', $request)->assertSessionHasNoErrors()->assertRedirect();
        // Asking for a price is counted on its own, so the busy user can still see one.
        $this->actingAs($busy)->postJson('/generations/estimate', $request)->assertOk();
    });

    it('allows 120 price previews a minute and not one more', function (): void {
        $this->fakePricing();
        $request = ['prompt' => 'A red fox', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []];

        foreach (range(1, 120) as $ignored) {
            $this->actingAs($this->user)->postJson('/generations/estimate', $request)->assertOk();
        }

        $this->actingAs($this->user)->postJson('/generations/estimate', $request)->assertTooManyRequests();
    });
});
