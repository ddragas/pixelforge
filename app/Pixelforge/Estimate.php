<?php

namespace App\Pixelforge;

/**
 * What a generation may cost the user, in nanodollars, before it has run.
 * The lowest and highest are hard bounds; the expected one is a midpoint.
 */
final readonly class Estimate
{
    public function __construct(
        public int $lowest,
        public int $expected,
        public int $highest,
    ) {}
}
