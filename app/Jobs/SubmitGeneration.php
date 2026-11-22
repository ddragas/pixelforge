<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Repositories\GenerationRepository;
use App\Services\GenerationLifecycle;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Hands one generation to Pixelforge.
 *
 * Pixelforge refuses requests when it is busy (3 at a time, 60 a minute,
 * occasional overloads). Those refusals create nothing, so the job simply
 * waits as long as Pixelforge asks and tries again, for a limited time. Our
 * own id is the idempotency key, so a retry after a lost answer returns the
 * same generation instead of making, and billing, a second one.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(public Generation $generation) {}

    public function retryUntil(): DateTimeInterface
    {
        return $this->generation->created_at->copy()->addMinutes((int) config('pixelforge.submit_within_minutes'));
    }

    /**
     * 2, 4, 8, 16, 32 seconds, then a minute at most; never less than
     * Pixelforge asked for in its Retry-After header.
     */
    public static function secondsToWait(int $attempt, ?int $retryAfter): int
    {
        return max($retryAfter ?? 0, min(60, 2 ** min($attempt, 6)));
    }

    public function handle(PixelforgeClient $pixelforge, GenerationLifecycle $lifecycle, GenerationRepository $generations): void
    {
        $generation = $generations->fresh($this->generation);

        if ($generation->status !== GenerationStatus::Pending) {
            return;
        }

        try {
            $remote = $pixelforge->createGeneration([
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                'metadata' => ['generation_id' => $generation->id],
            ], idempotencyKey: $generation->id);
        } catch (PixelforgeException $exception) {
            if ($exception->isTransient()) {
                // Wait as long as we are told, and a little longer each time.
                $this->release(self::secondsToWait($this->attempts(), $exception->retryAfter));

                return;
            }

            $lifecycle->abandon($generation, $exception->errorCode, $this->explain($exception));

            return;
        }

        $lifecycle->apply($remote, $generation);
    }

    /**
     * Called when the time to submit has run out.
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") The signature is Laravel's.
     */
    public function failed(?Throwable $exception): void
    {
        resolve(GenerationLifecycle::class)->abandon(
            $this->generation,
            'provider_unavailable',
            'The image service was too busy to accept this request. You were not charged; please try again.',
        );
    }

    private function explain(PixelforgeException $exception): string
    {
        return match ($exception->errorCode) {
            'content_policy_violation' => 'The prompt was rejected by the image service. You were not charged.',
            'daily_limit_exceeded' => 'The image service has reached its daily limit. You were not charged; please try again tomorrow.',
            'validation_failed' => 'The image service did not accept this request: '.$exception->getMessage(),
            default => 'The image service could not accept this request. You were not charged.',
        };
    }
}
