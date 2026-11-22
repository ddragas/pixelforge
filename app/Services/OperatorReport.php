<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Repositories\GenerationRepository;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * For the operators: what users were charged, what Pixelforge charged us,
 * and the difference, per UTC day and per user. Pixelforge bills on the UTC
 * day a generation completed, so that is the day used here too, and each
 * day is compared with Pixelforge's own usage report.
 */
class OperatorReport
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly GenerationRepository $generations,
    ) {}

    /**
     * Today and the 29 days before it.
     *
     * @return array{days: list<array<string, mixed>>, totals: array<string, mixed>, usageError: string|null, range: array{start: string, end: string}}
     */
    public function lastThirtyDays(): array
    {
        $end = Date::now('UTC')->startOfDay();
        $start = $end->copy()->subDays(29);

        $rows = $this->generations->succeededSince($start)
            ->groupBy(fn (Generation $generation): string => ($generation->completed_at ?? $generation->updated_at)->utc()->toDateString());

        try {
            /** @var list<array<string, mixed>> $usage */
            $usage = $this->pixelforge->usage($start->toDateString(), $end->toDateString())['data'];
            $reported = collect($usage)->keyBy('date');
            $usageError = null;
        } catch (PixelforgeException $exception) {
            $reported = collect();
            $usageError = $exception->getMessage();
        }

        $days = [];

        for ($day = $end->copy(); $day->gte($start); $day->subDay()) {
            $date = $day->toDateString();
            $ours = $rows->get($date, collect());
            $theirs = $reported->get($date);

            if ($ours->isEmpty() && (int) ($theirs['generations'] ?? 0) === 0) {
                continue;
            }

            $providerCost = (int) $ours->sum('provider_cost');
            $reportedCost = $theirs === null ? null : Money::fromDecimal($theirs['cost_usd']);

            $days[] = [
                'date' => $date,
                'generations' => $ours->count(),
                ...$this->money($ours),
                'reported_generations' => $theirs['generations'] ?? null,
                'reported_cost' => $reportedCost === null ? null : Money::toDecimal($reportedCost),
                'matches' => $reportedCost === null ? null : $reportedCost === $providerCost && (int) $theirs['generations'] === $ours->count(),
                'users' => $ours->groupBy('user_id')->map(fn ($generations): array => [
                    'name' => $generations->first()->user->name,
                    'email' => $generations->first()->user->email,
                    'generations' => $generations->count(),
                    ...$this->money($generations),
                ])->values()->all(),
            ];
        }

        return [
            'days' => $days,
            'totals' => ['generations' => $rows->flatten(1)->count(), ...$this->money($rows->flatten(1))],
            'usageError' => $usageError,
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
        ];
    }

    /**
     * @param  Collection<int, Generation>  $generations
     * @return array{charged: string, provider_cost: string, profit: string}
     */
    private function money($generations): array
    {
        $charged = (int) $generations->sum('charged');
        $providerCost = (int) $generations->sum('provider_cost');

        return [
            'charged' => Money::toDecimal($charged),
            'provider_cost' => Money::toDecimal($providerCost),
            'profit' => Money::toDecimal($charged - $providerCost),
        ];
    }
}
