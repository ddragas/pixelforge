<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\CostCalculator;
use App\Pixelforge\Estimate;
use App\Pixelforge\PixelforgeClient;
use App\Repositories\GenerationRepository;
use Illuminate\Support\Facades\DB;

/**
 * Taking an order for an image: what it may cost, whether the user can
 * afford it, and setting the credit aside when they go ahead.
 */
class GenerationOrders
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly CostCalculator $calculator,
        private readonly Credits $credits,
        private readonly GenerationRepository $generations,
    ) {}

    /** What the order may cost, at Pixelforge's current prices. */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImages): Estimate
    {
        return $this->calculator->estimate($prompt, $size, $quality, $referenceImages, $this->pixelforge->pricing());
    }

    /** Can the user pay for it even if it comes out at the highest price? */
    public function affordable(User $user, Estimate $estimate): bool
    {
        return $this->credits->available($user) >= $estimate->highest;
    }

    /**
     * Record the order and set the most it can cost aside. It is settled to
     * the real cost when the generation finishes, or released if it fails.
     *
     * @param  list<string>  $referenceImages
     *
     * @throws InsufficientCredit
     */
    public function place(User $user, string $prompt, string $size, string $quality, array $referenceImages): Generation
    {
        $estimate = $this->estimate($prompt, $size, $quality, count($referenceImages));

        return DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $estimate): Generation {
            // Locking the user makes two orders placed at once queue up here,
            // so both cannot spend the same credit.
            $user = $this->credits->lock($user);

            if (! $this->affordable($user, $estimate)) {
                throw new InsufficientCredit($estimate->highest);
            }

            return $this->generations->create($user, [
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'status' => GenerationStatus::Pending,
                'estimated_lowest' => $estimate->lowest,
                'estimated_expected' => $estimate->expected,
                'estimated_highest' => $estimate->highest,
                'reserved' => $estimate->highest,
            ]);
        });
    }
}
