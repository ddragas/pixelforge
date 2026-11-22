<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;

/*
 * Rector is run as a check ("--dry-run"): the build fails if the code could
 * be written in a more modern or more precise way that it knows of.
 */
return RectorConfig::configure()
    ->withPaths([__DIR__.'/app', __DIR__.'/database', __DIR__.'/routes', __DIR__.'/tests'])
    ->withPhpSets(php84: true)
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true, earlyReturn: true)
    ->withSets([LaravelSetList::LARAVEL_CODE_QUALITY, LaravelSetList::LARAVEL_COLLECTION]);
