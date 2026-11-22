<?php

declare(strict_types=1);

/*
 * Load test: a storm of webhooks against the running application.
 *
 * The ordinary tests run on an in-memory database with the queue switched
 * off. This one uses the real stack: the web server, PostgreSQL, Redis and
 * as many queue workers as are running. It delivers every event twice, in
 * random order, many at a time, and then checks the books:
 *
 *   - every generation ended up "succeeded",
 *   - every generation was charged exactly once,
 *   - the balance fell by exactly the sum of the charges,
 *   - no credit is left set aside.
 *
 * Usage:  php tests/Load/webhook-storm.php [generations=200] [concurrency=20]
 * Needs:  the app running at APP_URL, a queue worker, PIXELFORGE_WEBHOOK_SECRET set.
 * It creates one throwaway user and removes everything it made afterwards.
 */

use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$count = (int) ($argv[1] ?? 200);
$concurrency = (int) ($argv[2] ?? 20);
$secret = (string) config('pixelforge.webhook_secret');
$url = rtrim((string) config('app.url'), '/').'/webhooks/pixelforge';

if ($secret === '') {
    fwrite(STDERR, "Set PIXELFORGE_WEBHOOK_SECRET first.\n");
    exit(2);
}

$run = 'load_'.Str::lower(Str::random(8));
$startingBalance = 1_000_000_000_000;
$usage = ['input_text_tokens' => 8, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1500, 'total_tokens' => 2608];
$rates = ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'];
$chargePerGeneration = 78_487_500; // 62,790,000 provider cost + 25 %

$user = User::factory()->create(['email' => "{$run}@load.test"]);
$user->forceFill(['balance' => $startingBalance])->save();

$deliveries = [];

foreach (range(1, $count) as $number) {
    $providerId = "gen_{$run}_{$number}";

    Generation::factory()->for($user)->queued($providerId)->create();

    foreach (['queued', 'processing', 'succeeded'] as $status) {
        $body = json_encode([
            'id' => "evt_{$run}_{$number}_{$status}",
            'object' => 'event',
            'type' => "generation.{$status}",
            'data' => ['object' => [
                'id' => $providerId,
                'object' => 'generation',
                'status' => $status,
                'metadata' => [],
                'usage' => $status === 'succeeded' ? $usage : null,
                'rates' => $rates,
                'image_url' => $status === 'succeeded' ? "https://example.com/{$providerId}.png" : null,
                'image_url_expires_at' => $status === 'succeeded' ? now()->addDay()->toIso8601ZuluString() : null,
                'error' => null,
                'started_at' => $status === 'queued' ? null : now()->toIso8601ZuluString(),
                'completed_at' => $status === 'succeeded' ? now()->toIso8601ZuluString() : null,
            ]],
        ], JSON_THROW_ON_ERROR);

        // Every event is delivered twice.
        $deliveries[] = $body;
        $deliveries[] = $body;
    }
}

shuffle($deliveries);

printf("%d generations, %d deliveries, %d at a time, to %s\n", $count, count($deliveries), $concurrency, $url);

$times = [];
$statuses = [];
$started = microtime(true);

foreach (array_chunk($deliveries, $concurrency) as $batch) {
    $responses = Http::pool(fn (Pool $pool): array => array_map(function (string $body) use ($pool, $url, $secret) {
        $timestamp = time();

        return $pool->withHeaders([
            'Content-Type' => 'application/json',
            'Pixelforge-Signature' => "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret),
        ])->withBody($body, 'application/json')->timeout(30)->post($url);
    }, $batch));

    foreach ($responses as $response) {
        $status = $response instanceof Throwable ? 0 : $response->status();
        $statuses[$status] = ($statuses[$status] ?? 0) + 1;

        if (! $response instanceof Throwable) {
            $times[] = (float) ($response->transferStats?->getTransferTime() ?? 0.0);
        }
    }
}

$delivering = microtime(true) - $started;
sort($times);
$at = fn (float $share): float => $times === [] ? 0.0 : $times[(int) floor((count($times) - 1) * $share)] * 1000;

printf("Delivered in %.1f s (%.0f requests a second).\n", $delivering, count($deliveries) / max($delivering, 0.001));
printf("Answer times: median %.0f ms, 95th percentile %.0f ms, slowest %.0f ms.\n", $at(0.5), $at(0.95), $at(1.0));
printf("Answers: %s\n", json_encode($statuses));

// Wait for the queue workers to finish what the webhooks left for them.
$deadline = time() + 180;
$waiting = fn (): int => WebhookEvent::query()->where('id', 'like', "evt_{$run}_%")->whereNull('processed_at')->count();

while ($waiting() > 0 && time() < $deadline) {
    Sleep::usleep(500_000);
}

printf("Queue drained %.1f s after the first delivery.\n", microtime(true) - $started);

$generations = Generation::query()->where('user_id', $user->id);
$checks = [
    'every delivery was answered 204' => $statuses === [204 => count($deliveries)],
    'one stored event per event, despite two deliveries of each' => WebhookEvent::query()->where('id', 'like', "evt_{$run}_%")->count() === $count * 3,
    'no event left unprocessed' => $waiting() === 0,
    'every generation succeeded' => (clone $generations)->where('status', GenerationStatus::Succeeded)->count() === $count,
    'every generation charged exactly once' => CreditTransaction::query()->where('user_id', $user->id)->where('type', CreditTransaction::GENERATION)->count() === $count,
    'every charge is the right amount' => (int) (clone $generations)->where('charged', $chargePerGeneration)->count() === $count,
    'the balance fell by exactly the sum of the charges' => (int) User::query()->whereKey($user->id)->value('balance') === $startingBalance - $count * $chargePerGeneration,
    'no credit is left set aside' => (int) (clone $generations)->sum('reserved') === 0,
];

foreach ($checks as $what => $holds) {
    printf("  %s  %s\n", $holds ? 'ok  ' : 'FAIL', $what);
}

// Leave nothing behind.
WebhookEvent::query()->where('id', 'like', "evt_{$run}_%")->delete();
CreditTransaction::query()->where('user_id', $user->id)->delete();
$generations->delete();
$user->delete();

exit(in_array(false, $checks, true) ? 1 : 0);
