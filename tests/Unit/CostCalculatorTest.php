<?php

namespace Tests\Unit;

use App\Pixelforge\CostCalculator;
use App\Pixelforge\Pricing;
use InvalidArgumentException;
use Tests\Support\Pixelforge;
use Tests\TestCase;

class CostCalculatorTest extends TestCase
{
    use Pixelforge;

    public function test_the_provider_cost_matches_the_worked_example_in_the_pixelforge_docs(): void
    {
        // "The most a generation can cost": 115 text, 1,530 image, 6,450 output and 8,000 reasoning tokens = $0.373875.
        $cost = (new CostCalculator)->providerCost([
            'input_text_tokens' => 115,
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'reasoning_tokens' => 8000,
        ], $this->rates());

        $this->assertSame(373_875_000, $cost);
    }

    public function test_the_provider_cost_matches_the_usage_example_in_the_pixelforge_docs(): void
    {
        // GET /v1/usage example: 41 text, 3,300 output and 2,950 reasoning tokens = "0.169080000".
        $cost = (new CostCalculator)->providerCost([
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 3300,
            'reasoning_tokens' => 2950,
        ], $this->rates());

        $this->assertSame(169_080_000, $cost);
    }

    public function test_the_user_pays_cost_plus_a_quarter(): void
    {
        $this->assertSame(467_343_750, (new CostCalculator)->userPrice(373_875_000));
    }

    public function test_a_fraction_of_a_nanodollar_is_rounded_up_never_down(): void
    {
        $calculator = new CostCalculator;

        $this->assertSame(0, $calculator->userPrice(0));
        $this->assertSame(2, $calculator->userPrice(1));   // 1.25
        $this->assertSame(3, $calculator->userPrice(2));   // 2.5
        $this->assertSame(4, $calculator->userPrice(3));   // 3.75
        $this->assertSame(5, $calculator->userPrice(4));   // 5 exactly
    }

    public function test_the_highest_estimate_is_the_most_the_documented_rules_allow(): void
    {
        $estimate = (new CostCalculator)->estimate(
            str_repeat('a', 400),
            '1536x1024',
            'high',
            2,
            Pricing::fromResponse($this->pricing()),
        );

        // The docs' worst case, plus our markup.
        $this->assertSame(467_343_750, $estimate->highest);
        // 85 text and 1,000 reasoning tokens at the low end: 0.286225 x 1.25.
        $this->assertSame(357_781_250, $estimate->lowest);
        $this->assertGreaterThan($estimate->lowest, $estimate->expected);
        $this->assertLessThan($estimate->highest, $estimate->expected);
    }

    public function test_a_prompt_is_counted_in_characters_not_bytes(): void
    {
        $pricing = Pricing::fromResponse($this->pricing());
        $calculator = new CostCalculator;

        $this->assertSame(
            $calculator->estimate('aaaa', '1024x1024', 'low', 0, $pricing)->highest,
            $calculator->estimate('šđčć', '1024x1024', 'low', 0, $pricing)->highest,
        );
    }

    public function test_a_rate_that_cannot_be_billed_in_whole_nanodollars_is_refused(): void
    {
        $this->assertSame(12_500, Pricing::nanosPerToken('12.50'));
        $this->assertSame(5_001, Pricing::nanosPerToken('5.001'));

        $this->expectException(InvalidArgumentException::class);

        Pricing::nanosPerToken('5.0001');
    }
}
