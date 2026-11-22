<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Webhooks can be lost. Ask about anything that has been quiet for a minute.
Schedule::command('pixelforge:reconcile')->everyMinute()->withoutOverlapping();
