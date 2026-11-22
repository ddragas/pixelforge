<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is kept as whole nanodollars (one billionth of a US dollar) in an int.
 *
 * Pixelforge quotes money with nine decimal places and never rounds, so a
 * nanodollar is the smallest amount that can ever appear. Whole numbers of
 * them add up exactly; floats do not.
 */
final class Money
{
    public const NANOS_PER_DOLLAR = 1_000_000_000;

    /** "0.046125000" -> 46125000. Refuses anything finer than a nanodollar. */
    public static function fromDecimal(string $dollars): int
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($dollars), $parts)) {
            throw new InvalidArgumentException("Not a decimal amount: [{$dollars}]");
        }

        $fraction = $parts[3] ?? '';

        if (strlen(rtrim($fraction, '0')) > 9) {
            throw new InvalidArgumentException("[{$dollars}] is finer than a nanodollar.");
        }

        $nanos = (int) $parts[2] * self::NANOS_PER_DOLLAR + (int) str_pad(substr($fraction, 0, 9), 9, '0');

        return $parts[1] === '-' ? -$nanos : $nanos;
    }

    /** 46125000 -> "0.046125000", always nine decimals, like Pixelforge's own figures. */
    public static function toDecimal(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $nanos = abs($nanos);

        return sprintf('%s%d.%09d', $sign, intdiv($nanos, self::NANOS_PER_DOLLAR), $nanos % self::NANOS_PER_DOLLAR);
    }
}
