<?php

namespace App\Pixelforge;

/**
 * All the arithmetic about money lives here, in whole nanodollars.
 */
final class CostCalculator
{
    /**
     * What Pixelforge bills for a finished generation: tokens x rate, per token type.
     *
     * @param  array<string, int>  $usage  token counts as reported by Pixelforge
     * @param  array<string, string>  $rates  the rates fixed when the generation was created
     */
    public function providerCost(array $usage, array $rates): int
    {
        $nanos = 0;

        foreach (Pricing::TOKEN_TYPES as $type) {
            $nanos += (int) ($usage[$type] ?? 0) * Pricing::nanosPerToken($rates[$type]);
        }

        return $nanos;
    }

    /**
     * What the user pays: the provider's cost plus our markup.
     *
     * A quarter on top of an odd number of nanodollars is not a whole
     * nanodollar. It is rounded UP, so we never charge less than cost plus
     * markup, and never more than one nanodollar above it.
     */
    public function userPrice(int $providerCost): int
    {
        $percent = 100 + (int) config('pixelforge.markup_percent');

        return intdiv($providerCost * $percent + 99, 100);
    }

    /**
     * The range a generation can cost the user, from the documented counting rules.
     *
     * Output and reference-image tokens are exact. Text tokens are known to
     * within the published variance, reasoning tokens within a range per quality.
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImages, Pricing $pricing): Estimate
    {
        $text = (int) ceil(mb_strlen($prompt) / $pricing->charactersPerTextToken);
        $variance = (int) round($pricing->textTokenVariance * 100);
        $reasoning = $pricing->reasoningTokens[$quality];

        $fixed = [
            'input_image_tokens' => $referenceImages * $pricing->tokensPerReferenceImage,
            'output_tokens' => $pricing->outputTokens[$quality][$size],
        ];

        $price = fn (int $textTokens, int $reasoningTokens): int => $this->userPrice($this->providerCost(
            $fixed + ['input_text_tokens' => $textTokens, 'reasoning_tokens' => $reasoningTokens],
            $pricing->rates,
        ));

        return new Estimate(
            lowest: $price(intdiv($text * (100 - $variance), 100), $reasoning['min']),
            expected: $price($text, intdiv($reasoning['min'] + $reasoning['max'], 2)),
            highest: $price(intdiv($text * (100 + $variance) + 99, 100), $reasoning['max']),
        );
    }
}
