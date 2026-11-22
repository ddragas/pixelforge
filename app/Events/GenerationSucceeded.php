<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Generation;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A generation has finished and been paid for. Announced only after the
 * transaction that settled it has been committed, so listeners can never
 * see, or act on, something that is later rolled back.
 */
final readonly class GenerationSucceeded
{
    use Dispatchable;

    public function __construct(public Generation $generation) {}
}
