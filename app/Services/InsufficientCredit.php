<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * The user does not have enough free credit for what they asked for.
 */
final class InsufficientCredit extends RuntimeException
{
    /** @param  int  $needed  nanodollars of free credit the request requires */
    public function __construct(public readonly int $needed)
    {
        parent::__construct('Not enough free credit.');
    }
}
