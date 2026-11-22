<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Generation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A generation has ended without an image and without a charge. Announced
 * only after the transaction that recorded it has been committed.
 */
final readonly class GenerationFailed
{
    use Dispatchable;

    public function __construct(public Generation $generation) {}
}
