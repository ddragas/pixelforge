<?php

use App\Jobs\ProcessWebhookEvent;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Pixelforge\PixelforgeClient;
use App\Services\Credits;
use App\Services\GenerationLifecycle;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;

/*
 * Rules about how the code is put together. They fail the build when
 * someone, later, takes a shortcut that the design was built to prevent.
 */

arch('nothing is left behind from debugging')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('no code relies on insecure or deprecated PHP')
    ->preset()->security();

arch('only the Pixelforge client talks HTTP')
    ->expect(Http::class)
    ->toOnlyBeUsedIn(PixelforgeClient::class);

arch('the Pixelforge layer knows nothing about our models, requests or jobs')
    ->expect('App\Pixelforge')
    ->not->toUse(['App\Models', 'App\Http', 'App\Jobs', 'App\Services']);

arch('money helpers depend on nothing of ours')
    ->expect(Money::class)
    ->toBeFinal()
    ->not->toUse('App');

arch('controllers do not change balances or statuses themselves')
    ->expect('App\Http\Controllers')
    ->not->toUse([CreditTransaction::class, GenerationLifecycle::class]);

arch('the ledger is written in one place only')
    ->expect(CreditTransaction::class)
    ->toOnlyBeUsedIn([Credits::class, 'App\Models']);

arch('jobs can be queued')
    ->expect([SubmitGeneration::class, ProcessWebhookEvent::class])
    ->toImplement(ShouldQueue::class);

arch('controllers are named as controllers')
    ->expect('App\Http\Controllers')
    ->toHaveSuffix('Controller');

arch('enums are enums')
    ->expect('App\Enums')
    ->toBeEnums();

arch('models are not used from the front door of the Pixelforge layer')
    ->expect('App\Services')
    ->not->toUse('App\Http');
