<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Repositories\GenerationRepository;
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

    public function handle(PixelforgeClient $pixelforge, GenerationLifecycle $lifecycle, GenerationRepository $generations): int
    {
        // Few per run: these requests count towards the 60 a minute limit that users' own requests share.
        $stale = $generations->stale((int) $this->option('older-than'), limit: 15);

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
