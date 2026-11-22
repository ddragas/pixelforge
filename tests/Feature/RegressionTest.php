<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\Pixelforge;

uses(Pixelforge::class);

/*
 * One test for each fault that was found while building this, named after
 * what went wrong, so that it cannot quietly come back.
 */

beforeEach(fn () => $this->setUpPixelforge());

it('accepts a webhook from outside although it carries no CSRF token', function (): void {
    // Found on the first real delivery: every test passed, Pixelforge got 419.
    // Tests run with CSRF checking off unless it is switched back on like this.
    $this->withMiddleware();
    Generation::factory()->for(User::factory()->create(['balance' => 1_000_000_000]))->queued()->create();

    $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded'))->assertNoContent();
});

it('says an image is affordable as soon as credit has been bought', function (): void {
    // Found in the browser: after buying credit the button stayed locked,
    // because the page kept the answer it was given before the purchase.
    $this->fakePricing();
    $user = User::factory()->create();
    $request = ['prompt' => 'A red fox in fresh snow', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => []];

    $this->actingAs($user)->postJson('/generations/estimate', $request)->assertJsonPath('affordable', false);
    $this->actingAs($user)->post('/credits', ['dollars' => 5]);
    $this->actingAs($user)->postJson('/generations/estimate', $request)->assertJsonPath('affordable', true);
});

it('records the answer to its own request even when Pixelforge sends no metadata back', function (): void {
    // Found by a test: the generation was looked up by the metadata in the
    // answer, and stayed "pending" when the answer did not carry it.
    Http::fake(['*/v1/generations' => Http::response($this->remoteGeneration('queued', ['metadata' => []]), 202)]);
    $generation = Generation::factory()->for(User::factory()->create())->create();

    dispatch_sync(new SubmitGeneration($generation));

    expect($generation->refresh())->provider_id->toBe('gen_01TEST')->status->toBe(GenerationStatus::Queued);
});

it('gives a brand new user a balance of zero rather than none', function (): void {
    // Found by a test: a user who had never been reloaded had no balance at
    // all, and every page crashed while sharing it.
    $this->actingAs(User::factory()->create())->get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('credit.balance', '0.000000000')->where('credit.available', '0.000000000'));
});

it('gives up on a generation Pixelforge will not accept after five minutes, not fifteen', function (): void {
    // Found with the [overloaded] keyword: a request could sit "waiting to be sent" for a quarter of an hour.
    $generation = Generation::factory()->create();

    expect((new SubmitGeneration($generation))->retryUntil()->getTimestamp())
        ->toBe($generation->created_at->copy()->addMinutes(5)->getTimestamp());
});

it('counts a prompt in characters, so accented letters do not cost double', function (): void {
    $this->fakePricing();
    $user = User::factory()->create(['balance' => 5_000_000_000]);
    $ask = fn (string $prompt) => $this->actingAs($user)
        ->postJson('/generations/estimate', ['prompt' => $prompt, 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []])
        ->json('highest');

    expect($ask('šđčćšđčć'))->toBe($ask('aaaaaaaa'));
});
