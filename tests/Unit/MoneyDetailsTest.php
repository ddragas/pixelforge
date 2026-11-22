<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Pixelforge\PixelforgeException;
use App\Pixelforge\Pricing;
use App\Support\Money;

/*
 * The edges of the money arithmetic. Each line here exists because mutation
 * testing showed the code could be changed there without a test failing.
 */

it('reads decimal dollars as whole nanodollars', function (string $dollars, int $nanos): void {
    expect(Money::fromDecimal($dollars))->toBe($nanos);
})->with([
    'whole dollars' => ['5', 5_000_000_000],
    'one decimal' => ['0.5', 500_000_000],
    'nine decimals' => ['0.123456789', 123_456_789],
    'the smallest amount' => ['0.000000001', 1],
    'zeros after the ninth decimal are harmless' => ['0.1000000000', 100_000_000],
    'and many of them' => ['2.50000000000000', 2_500_000_000],
    'spaces around it' => [' 5 ', 5_000_000_000],
    'negative' => ['-0.5', -500_000_000],
    'negative whole' => ['-3', -3_000_000_000],
    'zero' => ['0', 0],
]);

it('refuses what is not a plain decimal amount', function (string $dollars): void {
    Money::fromDecimal($dollars);
})->with(['', 'abc', '1,5', '1.', '.5', '1e3', '0.0000000001', '5 dollars'])->throws(InvalidArgumentException::class);

it('writes nanodollars as dollars with exactly nine decimals', function (int $nanos, string $dollars): void {
    expect(Money::toDecimal($nanos))->toBe($dollars);
})->with([
    [0, '0.000000000'],
    [1, '0.000000001'],
    [-1, '-0.000000001'],
    [999_999_999, '0.999999999'],
    [1_000_000_000, '1.000000000'],
    [-1_000_000_001, '-1.000000001'],
    [78_487_500, '0.078487500'],
]);

it('turns a price per million tokens into whole nanodollars per token', function (string $rate, int $nanos): void {
    expect(Pricing::nanosPerToken($rate))->toBe($nanos);
})->with([
    ['5.00', 5_000],
    ['12.50', 12_500],
    ['12.5', 12_500],
    ['10', 10_000],
    ['0.001', 1],
    ['0.125', 125],
    ['40.000', 40_000],
    'zeros after the third decimal are harmless' => ['12.5000', 12_500],
    'spaces around it' => [' 12.50 ', 12_500],
]);

it('refuses a rate that cannot be billed in whole nanodollars', function (string $rate): void {
    Pricing::nanosPerToken($rate);
})->with(['0.0001', '12.5001', '', 'free', '-5.00', '5,00'])->throws(InvalidArgumentException::class);

it('orders the statuses so that news can only move a generation forward', function (): void {
    expect(GenerationStatus::Pending->rank())->toBe(0)
        ->and(GenerationStatus::Queued->rank())->toBe(1)
        ->and(GenerationStatus::Processing->rank())->toBe(2)
        ->and(GenerationStatus::Succeeded->rank())->toBe(3)
        ->and(GenerationStatus::Failed->rank())->toBe(3);
});

it('knows which statuses are the end of the road', function (GenerationStatus $status, bool $final): void {
    expect($status->isFinal())->toBe($final);
})->with([
    [GenerationStatus::Pending, false],
    [GenerationStatus::Queued, false],
    [GenerationStatus::Processing, false],
    [GenerationStatus::Succeeded, true],
    [GenerationStatus::Failed, true],
]);

it('tells refusals worth retrying from final ones', function (string $code, bool $retry): void {
    $exception = new PixelforgeException($code, 'What Pixelforge said.', 429, 7);

    expect($exception->isTransient())->toBe($retry)
        ->and($exception->getMessage())->toBe('What Pixelforge said.')
        ->and($exception->status)->toBe(429)
        ->and($exception->retryAfter)->toBe(7);
})->with([
    ['rate_limit_exceeded', true],
    ['concurrency_limit_exceeded', true],
    ['overloaded', true],
    ['connection_failed', true],
    ['server_error', true],
    ['content_policy_violation', false],
    ['invalid_api_key', false],
    ['invalid_request', false],
    ['unknown_error', false],
]);
