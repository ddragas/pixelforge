<?php

declare(strict_types=1);

use App\Pixelforge\CostCalculator;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Pixelforge\Pricing;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\Pixelforge;

uses(Pixelforge::class);

/*
 * Every expected figure below was worked out by hand from the documented
 * rates (nanodollars per token: text 5,000, reference image 10,000,
 * output 40,000, reasoning 12,500), not copied from the program's output.
 */

beforeEach(fn () => $this->setUpPixelforge());

function pricingWith(array $tokens, array $documented): Pricing
{
    return Pricing::fromResponse(array_replace_recursive($documented, ['tokens' => $tokens]));
}

it('charges nothing for token types Pixelforge did not report', function (): void {
    expect((new CostCalculator)->providerCost(['output_tokens' => 10], $this->rates()))->toBe(400_000);
});

it('adds the markup and rounds up to the next nanodollar, whatever the markup is', function (int $markup, int $cost, int $price): void {
    config(['pixelforge.markup_percent' => $markup]);

    expect((new CostCalculator)->userPrice($cost))->toBe($price);
})->with([
    'nothing costs nothing' => [25, 0, 0],
    '1.25 becomes 2' => [25, 1, 2],
    '2.5 becomes 3' => [25, 2, 3],
    '3.75 becomes 4' => [25, 3, 4],
    'exactly 5 stays 5' => [25, 4, 5],
    'a real generation' => [25, 62_790_000, 78_487_500],
    '129.01 becomes 130' => [33, 97, 130],
    'exactly 133 stays 133' => [33, 100, 133],
    'no markup changes nothing' => [0, 97, 97],
]);

it('gives the range for a medium image with two reference images', function (): void {
    // 400 characters = 100 text tokens, 15 % either way: 85 to 115.
    // Fixed: 2 x 765 image tokens (15,300,000) + 1,100 output tokens (44,000,000) = 59,300,000.
    $estimate = (new CostCalculator)->estimate(str_repeat('a', 400), '1024x1024', 'medium', 2, pricingWith(['text_token_variance' => 0.15], $this->pricing()));

    expect($estimate->lowest)->toBe(77_781_250)    // + 85 text (425,000) + 200 reasoning (2,500,000) = 62,225,000, x 1.25
        ->and($estimate->expected)->toBe(91_937_500)  // + 100 text (500,000) + 1,100 reasoning (13,750,000) = 73,550,000, x 1.25
        ->and($estimate->highest)->toBe(106_093_750); // + 115 text (575,000) + 2,000 reasoning (25,000,000) = 84,875,000, x 1.25
});

it('rounds the text range outwards: down for the lowest price, up for the highest', function (): void {
    // 276 characters = 69 text tokens, 29 % either way: 48.99 -> 48 and 89.01 -> 90.
    // Fixed: 280 output tokens = 11,200,000.
    $estimate = (new CostCalculator)->estimate(str_repeat('a', 276), '1024x1024', 'low', 0, pricingWith(['text_token_variance' => 0.29], $this->pricing()));

    expect($estimate->lowest)->toBe(14_300_000)   // + 48 text (240,000) + 0 reasoning = 11,440,000, x 1.25
        ->and($estimate->expected)->toBe(18_337_500) // + 69 text (345,000) + 250 reasoning (3,125,000) = 14,670,000, x 1.25
        ->and($estimate->highest)->toBe(22_375_000); // + 90 text (450,000) + 500 reasoning (6,250,000) = 17,900,000, x 1.25
});

it('reads the published variance as whole percent', function (float $variance, int $lowest, int $highest): void {
    // 400 characters = 100 text tokens; low quality: 280 output tokens (11,200,000) and 0 to 500 reasoning tokens.
    $estimate = (new CostCalculator)->estimate(str_repeat('a', 400), '1024x1024', 'low', 0, pricingWith(['text_token_variance' => $variance], $this->pricing()));

    expect($estimate->lowest)->toBe($lowest)->and($estimate->highest)->toBe($highest);
})->with([
    // 0.14 x 100 is 14.000000000000002 in floating point: it must still mean 14 %, so 86 to 114 text tokens.
    '14 %' => [0.14, 14_537_500, 22_525_000],   // 11,630,000 x 1.25 and (11,200,000 + 570,000 + 6,250,000) x 1.25
    // 60 %: 40 to 160 text tokens.
    '60 %' => [0.60, 14_250_000, 22_812_500],   // 11,400,000 x 1.25 and (11,200,000 + 800,000 + 6,250,000) x 1.25
    'none' => [0.0, 14_625_000, 22_437_500],    // 11,700,000 x 1.25 and (11,200,000 + 500,000 + 6,250,000) x 1.25
]);

it('counts a started token as a whole token', function (): void {
    // 5 characters = 1.25 -> 2 text tokens; 15 % either way: 1.7 -> 1 and 2.3 -> 3.
    $estimate = (new CostCalculator)->estimate('abcde', '1024x1024', 'low', 0, pricingWith(['text_token_variance' => 0.15], $this->pricing()));

    expect($estimate->lowest)->toBe(14_006_250)   // 11,200,000 + 5,000 = 11,205,000, x 1.25
        ->and($estimate->expected)->toBe(17_918_750) // 11,200,000 + 10,000 + 3,125,000 = 14,335,000, x 1.25
        ->and($estimate->highest)->toBe(21_831_250); // 11,200,000 + 15,000 + 6,250,000 = 17,465,000, x 1.25
});

it('never estimates the lowest above the expected, or the expected above the highest', function (string $quality, string $size, int $references, int $length): void {
    $estimate = (new CostCalculator)->estimate(str_repeat('a', $length), $size, $quality, $references, Pricing::fromResponse($this->pricing()));

    expect($estimate->lowest)->toBeLessThanOrEqual($estimate->expected)
        ->and($estimate->expected)->toBeLessThanOrEqual($estimate->highest)
        ->and($estimate->lowest)->toBeGreaterThan(0);
})->with(['low', 'medium', 'high'])->with(['1024x1024', '1536x1024', '1024x1536'])->with([0, 4])->with([1, 37, 2000]);

it('asks Pixelforge for usage between the two dates given', function (): void {
    Http::fake(['*/v1/usage*' => Http::response(['object' => 'usage', 'data' => []])]);

    resolve(PixelforgeClient::class)->usage('2026-11-01', '2026-11-22');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer pf_test')
        && str_contains($request->url(), 'start_date=2026-11-01')
        && str_contains($request->url(), 'end_date=2026-11-22'));
});

it('falls back on the last prices it knew when Pixelforge cannot be reached', function (): void {
    Http::fake(['*/v1/pricing' => Http::sequence()->push($this->pricing())->push('', 503)]);
    $client = resolve(PixelforgeClient::class);

    $first = $client->pricing();
    Cache::forget('pixelforge.pricing'); // as if the minute had passed
    $second = $client->pricing();

    expect($second->rates)->toBe($first->rates)->toBe($this->rates());
    Http::assertSentCount(2);
});

it('says so when it has never known the prices and Pixelforge cannot be reached', function (): void {
    Http::fake(['*/v1/pricing' => Http::response('', 503)]);

    resolve(PixelforgeClient::class)->pricing();
})->throws(PixelforgeException::class);

it('turns each kind of refusal into an error the rest of the code can act on', function (int $status, mixed $body, array $headers, string $code, string $message, bool $retry, ?int $retryAfter): void {
    Http::fake(['*/v1/generations/*' => Http::response($body, $status, $headers)]);

    try {
        resolve(PixelforgeClient::class)->generation('gen_01TEST');
        $this->fail('No exception was thrown.');
    } catch (PixelforgeException $exception) {
        expect($exception->errorCode)->toBe($code)
            ->and($exception->getMessage())->toBe($message)
            ->and($exception->status)->toBe($status)
            ->and($exception->isTransient())->toBe($retry)
            ->and($exception->retryAfter)->toBe($retryAfter);
    }
})->with([
    'a documented error' => [429, ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down.']], ['Retry-After' => '7'], 'rate_limit_exceeded', 'Slow down.', true, 7],
    'a server error with no explanation' => [503, '', [], 'server_error', 'Pixelforge answered with HTTP 503.', true, null],
    'another error with no explanation' => [404, '', [], 'unknown_error', 'Pixelforge answered with HTTP 404.', false, null],
    'a Retry-After that is a date, not seconds' => [429, ['error' => ['code' => 'overloaded', 'message' => 'Busy.']], ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], 'overloaded', 'Busy.', true, null],
]);
