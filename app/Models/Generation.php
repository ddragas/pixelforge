<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $provider_id
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_images
 * @property GenerationStatus $status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $estimated_lowest
 * @property int $estimated_expected
 * @property int $estimated_highest
 * @property int $reserved
 * @property array<string, string>|null $rates
 * @property array<string, int>|null $usage
 * @property int|null $provider_cost
 * @property int|null $charged
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $checked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'estimated_lowest' => 'integer',
            'estimated_expected' => 'integer',
            'estimated_highest' => 'integer',
            'reserved' => 'integer',
            'provider_cost' => 'integer',
            'charged' => 'integer',
            'rates' => 'array',
            'usage' => 'array',
            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generations that have not reached succeeded or failed yet.
     *
     * @param  Builder<Generation>  $query
     */
    protected function scopeUnfinished(Builder $query): void
    {
        $query->whereNotIn('status', [GenerationStatus::Succeeded, GenerationStatus::Failed]);
    }
}
