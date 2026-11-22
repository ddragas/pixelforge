<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'prompt' => 'A lighthouse on a cliff at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'status' => GenerationStatus::Pending,
            'estimated_lowest' => 56_250_000,
            'estimated_expected' => 68_750_000,
            'estimated_highest' => 86_250_000,
            'reserved' => 86_250_000,
        ];
    }

    /** Accepted by Pixelforge and waiting in its queue. */
    public function queued(string $providerId = 'gen_01TEST'): static
    {
        return $this->state([
            'provider_id' => $providerId,
            'status' => GenerationStatus::Queued,
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ]);
    }
}
