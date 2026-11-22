<?php

namespace App\Http\Resources;

use App\Models\Generation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Generation
 *
 * Money goes to the browser as exact decimal strings, never as numbers,
 * so JavaScript cannot round it.
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") The signature is Laravel's.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'status' => $this->status->value,
            'error_message' => $this->error_message,
            'estimate' => [
                'lowest' => Money::toDecimal($this->estimated_lowest),
                'expected' => Money::toDecimal($this->estimated_expected),
                'highest' => Money::toDecimal($this->estimated_highest),
            ],
            'charged' => $this->charged === null ? null : Money::toDecimal($this->charged),
            'image_url' => $this->image_url_expires_at?->isFuture() ? $this->image_url : null,
            'image_expired' => $this->image_url !== null && ! $this->image_url_expires_at?->isFuture(),
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
