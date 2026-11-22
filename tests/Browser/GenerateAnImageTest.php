<?php

declare(strict_types=1);

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\Pixelforge;

uses(Pixelforge::class);

/*
 * The whole journey in a real browser, against the real pages and the real
 * JavaScript. Only Pixelforge itself is played by the test.
 */

beforeEach(function (): void {
    $this->setUpPixelforge();
    // What Pixelforge answers when asked to create a generation; a test may change it.
    $this->pixelforgeAnswers = fn () => Http::response($this->remoteGeneration('queued'), 202);

    Http::fake([
        '*/v1/pricing' => Http::response($this->pricing()),
        '*/v1/generations' => fn () => ($this->pixelforgeAnswers)(),
    ]);
});

function signIn(User $user): mixed
{
    return visit('/login')
        ->fill('#email', $user->email)
        ->fill('#password', 'password')
        ->press('Log in')
        ->assertPathIs('/generate');
}

it('takes a new user from an empty wallet to a finished, correctly charged image', function (): void {
    $user = User::factory()->create();

    $page = signIn($user)
        ->assertSee('Create an image')
        ->assertSee('Write a prompt and the price appears here.')
        ->assertSeeIn('[data-test=balance]', '$0.00');

    // A price appears as soon as there is a prompt; with no credit it cannot be sent.
    $page->fill('#prompt', 'A red fox in fresh snow')
        ->assertSee('Most likely')
        ->assertSee('If it fails, you pay nothing.')
        ->assertSee('of free credit for this image. Add credit below to continue.')
        ->assertButtonDisabled('Generate image');

    // Buying credit unlocks the button without reloading or retyping.
    $page->click('[data-test=buy-5]')
        ->assertSeeIn('[data-test=balance]', '$5.00')
        ->assertDontSee('Add credit below to continue.')
        ->assertButtonEnabled('Generate image');

    $page->press('Generate image')
        ->assertPathIs('/history')
        ->assertSee('A red fox in fresh snow')
        ->assertSeeIn('[data-test=status]', 'In the queue')
        ->assertSee('Will cost');

    $generation = Generation::sole();
    expect($generation->provider_id)->toBe('gen_01TEST')
        ->and($generation->reserved)->toBe($generation->estimated_highest);

    // Pixelforge finishes the image and tells us. The open page notices by itself.
    $this->deliverWebhook('evt_1', 'generation.succeeded', $this->remoteGeneration('succeeded'))->assertNoContent();

    $page->wait(6)
        ->assertSeeIn('[data-test=status]', 'Done')
        ->assertSee('You paid')
        ->assertSee('$0.0784875')
        ->assertNoJavaScriptErrors();

    expect($user->refresh()->balance)->toBe(5_000_000_000 - 78_487_500);
});

it('shows a failed image as failed and not charged, with the credit back', function (): void {
    $user = User::factory()->create(['balance' => 5_000_000_000]);

    $page = signIn($user)
        ->fill('#prompt', 'A red fox in fresh snow')
        ->assertSee('Most likely')
        ->press('Generate image')
        ->assertPathIs('/history')
        ->assertSeeIn('[data-test=status]', 'In the queue');

    $this->deliverWebhook('evt_1', 'generation.failed', $this->remoteGeneration('failed'))->assertNoContent();

    $page->wait(6)
        ->assertSeeIn('[data-test=status]', 'Failed')
        ->assertSee('Not charged')
        ->assertNoJavaScriptErrors();

    expect($user->refresh()->balance)->toBe(5_000_000_000);
});

it('says so at once when Pixelforge rejects the prompt', function (): void {
    $this->pixelforgeAnswers = fn () => Http::response(['error' => ['code' => 'content_policy_violation', 'message' => 'Rejected.']], 400);
    $user = User::factory()->create(['balance' => 5_000_000_000]);

    signIn($user)
        ->fill('#prompt', 'Something not allowed')
        ->assertSee('Most likely')
        ->press('Generate image')
        ->assertPathIs('/history')
        ->assertSeeIn('[data-test=status]', 'Failed')
        ->assertSee('Not charged');

    expect($user->refresh()->balance)->toBe(5_000_000_000);
});

it('keeps the operators\' report away from customers and shows it to operators', function (): void {
    Http::fake(['*/v1/usage*' => Http::response(['object' => 'usage', 'data' => []])]);

    signIn(User::factory()->create())->navigate('/admin/report')->assertSee('403');
});

it('works on a phone', function (): void {
    $user = User::factory()->create(['balance' => 5_000_000_000]);

    visit('/login')->on()->mobile()
        ->fill('#email', $user->email)
        ->fill('#password', 'password')
        ->press('Log in')
        ->assertPathIs('/generate')
        ->fill('#prompt', 'A red fox in fresh snow')
        ->assertSee('Most likely')
        ->assertButtonEnabled('Generate image')
        ->assertNoJavaScriptErrors();
});

it('sends guests to the sign-in page', function (): void {
    visit('/generate')->assertPathIs('/login');
    visit('/history')->assertPathIs('/login');
});
