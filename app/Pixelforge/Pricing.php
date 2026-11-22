<?php

namespace App\Pixelforge;

use InvalidArgumentException;

/**
 * Pixelforge's published prices and token counting rules (GET /v1/pricing).
 */
final readonly class Pricing
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * @param  array<string, string>  $rates  US dollars per one million tokens, as decimal strings
     * @param  array<string, array<string, int>>  $outputTokens  [quality][size] => tokens, exact
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  [quality] => range
     */
    public function __construct(
        public array $rates,
        public int $charactersPerTextToken,
        public float $textTokenVariance,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /** @param  array<string, mixed>  $response */
    public static function fromResponse(array $response): self
    {
        $tokens = $response['tokens'];

        return new self(
            rates: $response['rates'],
            charactersPerTextToken: (int) $tokens['characters_per_text_token'],
            textTokenVariance: (float) $tokens['text_token_variance'],
            tokensPerReferenceImage: (int) $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rates' => $this->rates,
            'tokens' => [
                'characters_per_text_token' => $this->charactersPerTextToken,
                'text_token_variance' => $this->textTokenVariance,
                'per_reference_image' => $this->tokensPerReferenceImage,
                'output' => $this->outputTokens,
                'reasoning' => $this->reasoningTokens,
            ],
        ];
    }

    /**
     * The price of ONE token in nanodollars.
     *
     * A rate is dollars per million tokens, so one token costs rate x 1000
     * nanodollars: "12.50" -> 12500. That is a whole number for any rate with
     * up to three decimals. A finer rate could not be billed in whole
     * nanodollars, so it is refused loudly rather than rounded quietly.
     */
    public static function nanosPerToken(string $ratePerMillion): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', trim($ratePerMillion), $parts)) {
            throw new InvalidArgumentException("Not a rate: [{$ratePerMillion}]");
        }

        $fraction = rtrim($parts[2] ?? '', '0');

        if (strlen($fraction) > 3) {
            throw new InvalidArgumentException("Rate [{$ratePerMillion}] is finer than a nanodollar per token.");
        }

        return (int) $parts[1] * 1000 + (int) str_pad($fraction, 3, '0');
    }
}
