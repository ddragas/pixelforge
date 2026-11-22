<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Services\GenerationLifecycle;
use Illuminate\Console\Command;

/**
 * Webhook delivery is not guaranteed. Any generation that Pixelforge has
 * accepted but that we have not heard about for a while is looked up
 * directly, so nothing stays "processing" for ever.
 */
class ReconcileGenerations extends Command
{
    protected $signature = 'pixelforge:reconcile {--older-than=60 : Seconds without news before we ask}';

    protected $description = 'Ask Pixelforge about generations we have not heard about lately';

    public function handle(PixelforgeClient $pixelforge, GenerationLifecycle $lifecycle): int
    {
        // Few per run: these requests count towards the 60 a minute limit that users' own requests share.
        $stale = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where('checked_at', '<', now()->subSeconds((int) $this->option('older-than')))
            ->oldest('checked_at')
            ->limit(15)
            ->get();

        foreach ($stale as $generation) {
            try {
                $lifecycle->apply($pixelforge->generation((string) $generation->provider_id));
            } catch (PixelforgeException $exception) {
                $this->warn("{$generation->provider_id}: {$exception->getMessage()}");

                if ($exception->isTransient()) {
                    break;
                }
            }
        }

        $this->info("Checked {$stale->count()} generation(s).");

        return self::SUCCESS;
    }
}
