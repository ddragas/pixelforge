<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Events\GenerationFailed;
use App\Events\GenerationSucceeded;
use App\Models\Generation;
use App\Pixelforge\CostCalculator;
use App\Repositories\GenerationRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Moves a generation from one status to the next, and settles the money
 * when it finishes.
 *
 * Everything we learn about a generation from Pixelforge comes through
 * apply(), whether it arrived in the answer to our own request, in a
 * webhook, or from polling. That way there is one set of rules, and news
 * that arrives twice, late or out of order cannot do harm.
 */
class GenerationLifecycle
{
    public function __construct(
        private readonly CostCalculator $calculator,
        private readonly Credits $credits,
        private readonly GenerationRepository $generations,
    ) {}

    /**
     * Take in a generation object as Pixelforge describes it.
     *
     * @param  array<string, mixed>  $remote
     * @param  Generation|null  $known  ours, when we already know which one it is (the answer to our own request)
     */
    public function apply(array $remote, ?Generation $known = null): ?Generation
    {
        return DB::transaction(function () use ($remote, $known): ?Generation {
            $generation = $this->findAndLock($remote, $known);

            if (! $generation instanceof Generation) {
                return null;
            }

            // A webhook may be the first we hear of the id: it can arrive
            // before our own create request has returned.
            $generation->provider_id ??= $remote['id'];
            $generation->rates ??= $remote['rates'] ?? null;
            $generation->checked_at = now();

            $incoming = GenerationStatus::from($remote['status']);

            // Only ever forward. A finished generation stays finished, and a
            // late "processing" does not undo a "succeeded".
            if ($incoming->rank() > $generation->status->rank()) {
                match ($incoming) {
                    GenerationStatus::Succeeded => $this->succeed($generation, $remote),
                    GenerationStatus::Failed => $this->fail(
                        $generation,
                        $remote['error']['code'] ?? 'generation_failed',
                        $remote['error']['message'] ?? 'The generation failed.',
                        $remote['completed_at'] ?? null,
                    ),
                    default => $generation->status = $incoming,
                };

                $generation->started_at ??= $this->time($remote['started_at'] ?? null);
            }

            $this->generations->save($generation);

            return $generation;
        });
    }

    /**
     * Give up on a generation Pixelforge never accepted. Nothing was created
     * there, so nothing is owed.
     */
    public function abandon(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message): void {
            $generation = $this->generations->lock($generation);

            if ($generation->status === GenerationStatus::Pending) {
                $this->fail($generation, $code, $message);
                $this->generations->save($generation);
            }
        });
    }

    /** @param  array<string, mixed>  $remote */
    private function succeed(Generation $generation, array $remote): void
    {
        $providerCost = $this->calculator->providerCost($remote['usage'], $generation->rates ?? $remote['rates']);
        $charged = $this->calculator->userPrice($providerCost);

        $generation->fill([
            'status' => GenerationStatus::Succeeded,
            'usage' => $remote['usage'],
            'provider_cost' => $providerCost,
            'charged' => $charged,
            'reserved' => 0,
            'image_url' => $remote['image_url'] ?? null,
            'image_url_expires_at' => $this->time($remote['image_url_expires_at'] ?? null),
            'completed_at' => $this->time($remote['completed_at'] ?? null) ?? now(),
        ]);

        $this->credits->charge($generation, $charged);

        // Told to the rest of the application only once it can no longer be undone.
        DB::afterCommit(static fn () => event(new GenerationSucceeded($generation)));
    }

    /** A failed generation costs nothing; the credit set aside for it is freed. */
    private function fail(Generation $generation, string $code, string $message, ?string $completedAt = null): void
    {
        $generation->fill([
            'status' => GenerationStatus::Failed,
            'error_code' => $code,
            'error_message' => $message,
            'provider_cost' => 0,
            'charged' => 0,
            'reserved' => 0,
            'completed_at' => $this->time($completedAt) ?? now(),
        ]);

        DB::afterCommit(static fn () => event(new GenerationFailed($generation)));
    }

    /**
     * Find our record by Pixelforge's id, or by our own id, which we sent as
     * metadata and Pixelforge returns with every object.
     *
     * @param  array<string, mixed>  $remote
     */
    private function findAndLock(array $remote, ?Generation $known = null): ?Generation
    {
        $ours = $known?->getKey() ?? $remote['metadata']['generation_id'] ?? null;

        return $this->generations->lockByProviderIdOrOwnId($remote['id'], $ours);
    }

    private function time(?string $value): ?Carbon
    {
        return $value === null ? null : Date::parse($value);
    }
}
