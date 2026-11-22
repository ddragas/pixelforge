<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Pixelforge;

uses(Pixelforge::class);

beforeEach(fn () => $this->setUpPixelforge());

function succeededOn(User $user, string $completedAt, int $providerCost = 62_790_000, int $charged = 78_487_500): Generation
{
    return Generation::factory()->for($user)->queued('gen_'.uniqid())->create([
        'status' => GenerationStatus::Succeeded,
        'provider_cost' => $providerCost,
        'charged' => $charged,
        'reserved' => 0,
        'completed_at' => $completedAt,
    ]);
}

describe('the operators\' report', function (): void {
    beforeEach(function (): void {
        Date::setTestNow('2026-11-05 10:00:00');
        $this->operator = User::factory()->create(['is_operator' => true]);
        $this->ana = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
        $this->ben = User::factory()->create(['name' => 'Ben', 'email' => 'ben@example.com']);
    });

    it('covers today and the 29 days before it, and asks Pixelforge about the same days', function (): void {
        Http::fake(['*/v1/usage*' => Http::response(['object' => 'usage', 'data' => []])]);
        succeededOn($this->ana, '2026-10-07 00:00:00'); // the first day in range
        succeededOn($this->ana, '2026-10-06 23:59:59'); // one second too early

        $props = $this->actingAs($this->operator)->get('/admin/report')->assertOk()->inertiaPage()['props'];

        expect($props['range'])->toBe(['start' => '2026-10-07', 'end' => '2026-11-05'])
            ->and(array_column($props['days'], 'date'))->toBe(['2026-10-07'])
            ->and($props['totals'])->toBe(['generations' => 1, 'charged' => '0.078487500', 'provider_cost' => '0.062790000', 'profit' => '0.015697500'])
            ->and($props['usageError'])->toBeNull();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'start_date=2026-10-07') && str_contains($request->url(), 'end_date=2026-11-05'));
    });

    it('breaks each day down by user, newest day first', function (): void {
        Http::fake(['*/v1/usage*' => Http::response(['object' => 'usage', 'data' => [
            ['date' => '2026-11-04', 'generations' => 3, 'cost_usd' => '0.162790000'],
            ['date' => '2026-11-03', 'generations' => 1, 'cost_usd' => '0.010000000'],
        ]])]);
        succeededOn($this->ana, '2026-11-04 09:00:00');
        succeededOn($this->ana, '2026-11-04 10:00:00', 60_000_000, 75_000_000);
        succeededOn($this->ben, '2026-11-04 11:00:00', 40_000_000, 50_000_000);
        succeededOn($this->ben, '2026-11-03 11:00:00', 10_000_000, 12_500_000);

        $props = $this->actingAs($this->operator)->get('/admin/report')->inertiaPage()['props'];

        expect($props['days'])->toHaveCount(2)
            ->and($props['days'][0])->toEqual([
                'date' => '2026-11-04',
                'generations' => 3,
                'charged' => '0.203487500',
                'provider_cost' => '0.162790000',
                'profit' => '0.040697500',
                'reported_generations' => 3,
                'reported_cost' => '0.162790000',
                'matches' => true,
                'users' => [
                    ['name' => 'Ana', 'email' => 'ana@example.com', 'generations' => 2, 'charged' => '0.153487500', 'provider_cost' => '0.122790000', 'profit' => '0.030697500'],
                    ['name' => 'Ben', 'email' => 'ben@example.com', 'generations' => 1, 'charged' => '0.050000000', 'provider_cost' => '0.040000000', 'profit' => '0.010000000'],
                ],
            ])
            ->and($props['days'][1]['date'])->toBe('2026-11-03')
            ->and($props['days'][1]['matches'])->toBeTrue()
            ->and($props['totals'])->toBe(['generations' => 4, 'charged' => '0.215987500', 'provider_cost' => '0.172790000', 'profit' => '0.043197500']);
    });

    it('flags a day when the money agrees but the number of generations does not', function (): void {
        Http::fake(['*/v1/usage*' => Http::response(['object' => 'usage', 'data' => [
            ['date' => '2026-11-04', 'generations' => 2, 'cost_usd' => '0.062790000'],
        ]])]);
        succeededOn($this->ana, '2026-11-04 09:00:00');

        $day = $this->actingAs($this->operator)->get('/admin/report')->inertiaPage()['props']['days'][0];

        expect($day['matches'])->toBeFalse()->and($day['reported_generations'])->toBe(2)->and($day['generations'])->toBe(1);
    });

    it('shows a day Pixelforge billed although we have nothing recorded for it', function (): void {
        Http::fake(['*/v1/usage*' => Http::response(['object' => 'usage', 'data' => [
            ['date' => '2026-11-02', 'generations' => 1, 'cost_usd' => '0.050000000'],
            ['date' => '2026-11-01', 'generations' => 0, 'cost_usd' => '0.000000000'],
        ]])]);

        $days = $this->actingAs($this->operator)->get('/admin/report')->inertiaPage()['props']['days'];

        // The 1st is left out: nothing happened on either side.
        expect($days)->toHaveCount(1)
            ->and($days[0])->toEqual([
                'date' => '2026-11-02', 'generations' => 0, 'charged' => '0.000000000', 'provider_cost' => '0.000000000', 'profit' => '0.000000000',
                'reported_generations' => 1, 'reported_cost' => '0.050000000', 'matches' => false, 'users' => [],
            ]);
    });

    it('shows a day we have recorded although Pixelforge reports nothing for it, without calling it a match or a mismatch', function (): void {
        Http::fake(['*/v1/usage*' => Http::response(['object' => 'usage', 'data' => []])]);
        succeededOn($this->ana, '2026-11-04 09:00:00');

        $day = $this->actingAs($this->operator)->get('/admin/report')->inertiaPage()['props']['days'][0];

        expect($day['reported_generations'])->toBeNull()->and($day['reported_cost'])->toBeNull()->and($day['matches'])->toBeNull()->and($day['generations'])->toBe(1);
    });

    it('still shows our own figures, and says why, when Pixelforge cannot be asked', function (): void {
        Http::fake(['*/v1/usage*' => Http::response('', 503)]);
        succeededOn($this->ana, '2026-11-04 09:00:00');

        $props = $this->actingAs($this->operator)->get('/admin/report')->assertOk()->inertiaPage()['props'];

        expect($props['usageError'])->toBe('Pixelforge answered with HTTP 503.')
            ->and($props['days'])->toHaveCount(1)
            ->and($props['days'][0]['matches'])->toBeNull()
            ->and($props['totals']['profit'])->toBe('0.015697500');
    });
});

describe('what the form accepts', function (): void {
    beforeEach(function (): void {
        $this->fakePricing();
        Queue::fake();
        $this->user = User::factory()->create(['balance' => 50_000_000_000]);
        $this->valid = ['prompt' => 'A red fox in fresh snow', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => []];
    });

    it('refuses a request with something wrong in it', function (array $change, string $field): void {
        $this->actingAs($this->user)->post('/generations', [...$this->valid, ...$change])->assertSessionHasErrors($field);

        expect(Generation::count())->toBe(0);
    })->with([
        'no prompt' => [['prompt' => ''], 'prompt'],
        'reference images given as a short piece of text' => [['reference_images' => 'abc'], 'reference_images'],
        'a prompt that is not text' => [['prompt' => ['a', 'b']], 'prompt'],
        'a prompt one character too long' => [fn (): array => ['prompt' => str_repeat('a', config('pixelforge.max_prompt_length') + 1)], 'prompt'],
        'no size' => [['size' => ''], 'size'],
        'a size Pixelforge does not offer' => [['size' => '2048x2048'], 'size'],
        'no quality' => [['quality' => ''], 'quality'],
        'a quality Pixelforge does not offer' => [['quality' => 'ultra'], 'quality'],
        'reference images that are not a list' => [['reference_images' => 'https://example.com/a.png'], 'reference_images'],
        'one reference image too many' => [fn (): array => ['reference_images' => array_fill(0, config('pixelforge.max_reference_images') + 1, 'https://example.com/a.png')], 'reference_images'],
        'an empty reference image' => [['reference_images' => ['']], 'reference_images.0'],
        'a reference image that is not an address' => [['reference_images' => ['not a url']], 'reference_images.0'],
        'a reference image address that is far too long' => [['reference_images' => ['https://example.com/'.str_repeat('a', 2000)]], 'reference_images.0'],
    ]);

    it('refuses a request with a field left out altogether', function (string $field): void {
        $request = $this->valid;
        unset($request[$field]);

        $this->actingAs($this->user)->post('/generations', $request)->assertSessionHasErrors($field);
        $this->actingAs($this->user)->postJson('/generations/estimate', $request)->assertJsonValidationErrors($field);

        expect(Generation::count())->toBe(0);
    })->with(['prompt', 'size', 'quality']);

    it('accepts the longest prompt and the most reference images allowed', function (): void {
        $images = array_fill(0, config('pixelforge.max_reference_images'), 'https://example.com/a.png');

        $this->actingAs($this->user)->post('/generations', [
            ...$this->valid,
            'prompt' => str_repeat('a', config('pixelforge.max_prompt_length')),
            'reference_images' => $images,
        ])->assertSessionHasNoErrors();

        expect(Generation::sole()->reference_images)->toBe($images);
    });

    it('stores reference images as a plain list, whatever keys the browser sent', function (): void {
        $this->actingAs($this->user)->post('/generations', [...$this->valid, 'reference_images' => ['first' => 'https://example.com/a.png', 'second' => 'https://example.com/b.png']])
            ->assertSessionHasNoErrors();

        expect(Generation::sole()->reference_images)->toBe(['https://example.com/a.png', 'https://example.com/b.png']);
    });

    it('refuses to sell credit when no amount is given at all', function (): void {
        $this->actingAs($this->user)->post('/credits', [])->assertSessionHasErrors('dollars');

        expect($this->user->refresh()->balance)->toBe(50_000_000_000);
    });

    it('sells each pack on offer for exactly its price', function (): void {
        foreach (config('pixelforge.credit_packs') as $dollars) {
            $before = $this->user->refresh()->balance;

            $this->actingAs($this->user)->post('/credits', ['dollars' => $dollars])->assertSessionHasNoErrors();

            expect($this->user->refresh()->balance)->toBe($before + $dollars * 1_000_000_000);
        }
    });
});

describe('the page with the form', function (): void {
    it('is given the choices and limits from the configuration', function (): void {
        $this->fakePricing();

        $props = $this->actingAs(User::factory()->create())->get('/generate')->assertOk()->inertiaPage()['props'];

        expect($props['sizes'])->toBe(config('pixelforge.sizes'))
            ->and($props['qualities'])->toBe(config('pixelforge.qualities'))
            ->and($props['maxReferenceImages'])->toBe(config('pixelforge.max_reference_images'))
            ->and($props['maxPromptLength'])->toBe(config('pixelforge.max_prompt_length'))
            ->and($props['creditPacks'])->toBe(config('pixelforge.credit_packs'))
            ->and($props['markupPercent'])->toBe(config('pixelforge.markup_percent'))
            ->and($props['recent'])->toBe([]);

        // The prices were fetched while the page was built, ready for the first preview.
        Http::assertSentCount(1);
    });

    it('still opens when Pixelforge is down, without filling the error log', function (): void {
        Exceptions::fake();
        Http::fake(['*/v1/pricing' => Http::response('', 503)]);

        $this->actingAs(User::factory()->create())->get('/generate')->assertOk();

        Exceptions::assertNothingReported();
    });

    it('shares the signed-in user\'s credit with every page, as exact text', function (): void {
        $this->fakePricing();
        $user = User::factory()->create(['balance' => 5_000_000_000]);
        Generation::factory()->for($user)->queued()->create(); // 0.08625 set aside

        $props = $this->actingAs($user)->get('/generate')->inertiaPage()['props'];

        expect($props['credit'])->toBe(['balance' => '5.000000000', 'available' => '4.913750000'])
            ->and($props['auth']['user']['email'])->toBe($user->email);
    });
});
