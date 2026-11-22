<?php

use Illuminate\Support\Facades\Schedule;

// Webhooks can be lost. Ask about anything that has been quiet for a minute.
Schedule::command('pixelforge:reconcile')->everyMinute()->withoutOverlapping();
