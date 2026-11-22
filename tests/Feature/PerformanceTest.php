<?php

use App\Enums\GenerationStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\CostCalculator;
use App\Pixelforge\Pricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Pixelforge;

uses(Pixelforge::class);

/*
 * Not benchmarks: guards. Each one fails if a page starts doing work in
 * proportion to how much data there is, which is how pages get slow.
 */

function queriesFor(Closure $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

function finished(User $user, int $count): void
{
    Generation::factory()->for($user)->count($count)->sequence(fn ($sequence) => ['provider_id' => 'gen_'.$user->id.'_'.$sequence->index.'_'.uniqid()])->create([
        'status' => GenerationStatus::Succeeded,
        'provider_cost' => 62_790_000,
        'charged' => 78_487_500,
        'reserved' => 0,
        'completed_at' => now()->subHour(),
    ]);
}

it('opens the history page with the same number of queries for one image as for a full page of them', function () {
    $few = User::factory()->create();
    $many = User::factory()->create();
    finished($few, 1);
    finished($many, 40);

    $forFew = queriesFor(fn () => $this->actingAs($few)->get('/history')->assertOk());
    $forMany = queriesFor(fn () => $this->actingAs($many)->get('/history')->assertOk());

    expect($forMany)->toBe($forFew)->toBeLessThanOrEqual(8);
});

it('builds the operators\' report with the same number of queries however many users and images there are', function () {
    $this->setUpPixelforge();
    Http::fake(['*/v1/usage*' => Http::response(['object' => 'usage', 'data' => []])]);
    $operator = User::factory()->create(['is_operator' => true]);

    finished(User::factory()->create(), 2);
    $small = queriesFor(fn () => $this->actingAs($operator)->get('/admin/report')->assertOk());

    foreach (range(1, 15) as $ignored) {
        finished(User::factory()->create(), 10);
    }
    $large = queriesFor(fn () => $this->actingAs($operator)->get('/admin/report')->assertOk());

    expect($large)->toBe($small);
});

it('answers a webhook without calling Pixelforge and leaves the work to the queue', function () {
    $this->setUpPixelforge();
    Http::fake();
    Queue::fake();
    Generation::factory()->queued()->create();

    $queries = queriesFor(fn () => $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded'))->assertNoContent());

    Http::assertNothingSent();
    Queue::assertPushed(ProcessWebhookEvent::class, 1);
    // One insert, one read: nothing about the generation itself happens before the reply.
    expect($queries)->toBeLessThanOrEqual(2);
});

it('prices ten thousand requests in well under a second', function () {
    $calculator = new CostCalculator;
    $pricing = Pricing::fromResponse($this->pricing());
    $prompt = str_repeat('A lighthouse on a cliff at dawn. ', 100);

    $started = hrtime(true);

    foreach (range(1, 10_000) as $ignored) {
        $calculator->estimate($prompt, '1536x1024', 'high', 4, $pricing);
    }

    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0);
});

it('does not ask Pixelforge for its prices more than once a minute', function () {
    $this->setUpPixelforge();
    $this->fakePricing();
    $user = User::factory()->create(['balance' => 5_000_000_000]);
    $request = ['prompt' => 'A red fox', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []];

    foreach (range(1, 5) as $ignored) {
        $this->actingAs($user)->postJson('/generations/estimate', $request)->assertOk();
    }

    Http::assertSentCount(1);
});
