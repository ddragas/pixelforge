<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Pixelforge;

uses(Pixelforge::class);

beforeEach(function (): void {
    $this->setUpPixelforge();
    $this->fakePricing();
});

$request = ['prompt' => 'A red fox in fresh snow', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []];

describe('who may see and do what', function (): void {
    it('never shows one user another user\'s images', function (): void {
        $mine = User::factory()->create();
        Generation::factory()->for(User::factory()->create())->create(['prompt' => 'somebody else\'s secret']);

        $this->actingAs($mine)->get('/history')
            ->assertOk()
            ->assertDontSee('somebody else\'s secret')
            ->assertInertia(fn ($page) => $page->has('generations', 0));
    });

    it('turns guests away from every page that costs or shows money', function (string $method, string $uri): void {
        $this->{$method}($uri)->assertRedirect('/login');
    })->with([
        ['get', '/generate'],
        ['get', '/history'],
        ['get', '/admin/report'],
        ['post', '/generations'],
        ['post', '/generations/estimate'],
        ['post', '/credits'],
    ]);

    it('keeps the operators\' report from ordinary users', function (): void {
        $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
    });

    it('does not let a user make themselves an operator or give themselves credit through their profile', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings/profile', [
            'name' => 'Mallory',
            'email' => $user->email,
            'is_operator' => true,
            'balance' => 999_000_000_000,
        ]);

        expect($user->refresh())->is_operator->toBeFalse()->balance->toBe(0)->name->toBe('Mallory');
    });

    it('does not let a new account arrive with credit or operator rights', function (): void {
        $this->post('/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'a-long-password-1',
            'password_confirmation' => 'a-long-password-1',
            'is_operator' => true,
            'balance' => 999_000_000_000,
        ]);

        expect(User::where('email', 'mallory@example.com')->sole())->is_operator->toBeFalse()->balance->toBe(0);
    });
});

describe('what a user may send', function () use ($request): void {
    it('refuses reference images that are not plain web addresses', function (string $url) use ($request): void {
        Queue::fake();

        $this->actingAs(User::factory()->create(['balance' => 5_000_000_000]))
            ->post('/generations', [...$request, 'reference_images' => [$url]])
            ->assertSessionHasErrors('reference_images.0');

        expect(Generation::count())->toBe(0);
    })->with(['javascript:alert(1)', 'file:///etc/passwd', 'ftp://example.com/a.png', 'data:image/png;base64,AAAA']);

    it('refuses to sell credit in amounts that are not on offer', function (mixed $dollars): void {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits', ['dollars' => $dollars])->assertSessionHasErrors('dollars');

        expect($user->refresh()->balance)->toBe(0);
    })->with([-5, 0, 0.01, 1_000_000, '5; drop table users', null]);

    it('stores a prompt with markup as text and nothing else', function () use ($request): void {
        Queue::fake();
        $prompt = '<script>alert(1)</script><img src=x onerror=alert(1)>';

        $this->actingAs(User::factory()->create(['balance' => 5_000_000_000]))->post('/generations', [...$request, 'prompt' => $prompt]);

        // Kept exactly as typed; the pages print it through Vue, which escapes it.
        expect(Generation::sole()->prompt)->toBe($prompt);
    });

    it('limits how fast one user can submit generations', function () use ($request): void {
        Queue::fake();
        $user = User::factory()->create(['balance' => 500_000_000_000]);

        foreach (range(1, 20) as $attempt) {
            $this->actingAs($user)->post('/generations', $request)->assertRedirect();
        }

        $this->actingAs($user)->post('/generations', $request)->assertTooManyRequests();
    });
});

describe('who may call the webhook', function (): void {
    beforeEach(function (): void {
        $this->generation = Generation::factory()->for(User::factory()->create(['balance' => 1_000_000_000]))->queued()->create();
    });

    it('refuses a request with no signature at all', function (): void {
        $this->postJson('/webhooks/pixelforge', ['id' => 'evt_1', 'type' => 'generation.succeeded'])->assertStatus(400);

        expect(WebhookEvent::count())->toBe(0);
    });

    it('refuses a body that was changed after it was signed', function (): void {
        $timestamp = time();
        $signed = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'generation.failed', 'data' => ['object' => $this->remoteGeneration('failed')]]);
        $sent = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'generation.succeeded', 'data' => ['object' => $this->remoteGeneration('succeeded')]]);

        $this->call('POST', '/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$signed}", $this->webhookSecret),
        ], content: $sent)->assertStatus(400);

        expect($this->generation->refresh()->status)->toBe(GenerationStatus::Queued);
    });

    it('refuses everything when no signing secret is configured, rather than accepting everything', function (): void {
        config(['pixelforge.webhook_secret' => null]);

        $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded'), secret: '')->assertStatus(400);
    });

    it('refuses a signature made for a timestamp in the future', function (): void {
        $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded'), timestamp: time() + 600)->assertStatus(400);
    });

    it('does not say whether a generation exists when the signature is wrong', function (): void {
        $known = $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded'), secret: 'wrong');
        $unknown = $this->deliverWebhook('evt_2', 'generation.succeeded', $this->remoteGeneration('succeeded', ['id' => 'gen_unknown']), secret: 'wrong');

        expect($known->status())->toBe(400)->and($unknown->status())->toBe(400)
            ->and($known->json('message'))->toBe($unknown->json('message'));
    });
});
