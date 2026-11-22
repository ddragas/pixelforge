<?php

declare(strict_types=1);

use Arkitect\ClassSet;
use Arkitect\CLI\Config;
use Arkitect\Expression\ForClasses\Extend;
use Arkitect\Expression\ForClasses\HaveNameMatching;
use Arkitect\Expression\ForClasses\Implement;
use Arkitect\Expression\ForClasses\IsEnum;
use Arkitect\Expression\ForClasses\IsFinal;
use Arkitect\Expression\ForClasses\NotDependsOnTheseNamespaces;
use Arkitect\Expression\ForClasses\NotHaveDependencyOutsideNamespace;
use Arkitect\Expression\ForClasses\ResideInOneOfTheseNamespaces;
use Arkitect\Rules\Rule;

/*
 * Rules about naming and about what each kind of class is allowed to be.
 * (Which layer may call which is Deptrac's job, in deptrac.yaml.)
 */
return static function (Config $config): void {
    $app = ClassSet::fromDir(__DIR__.'/app');

    $config->add(
        $app,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Http\Controllers'))
            ->should(new HaveNameMatching('*Controller'))
            ->because('a class in the controllers folder is a controller'),

        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Repositories'))
            ->should(new HaveNameMatching('*Repository'))
            ->because('a class in the repositories folder is a repository'),

        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Repositories'))
            ->should(new NotDependsOnTheseNamespaces(['App\Services', 'App\Http', 'App\Jobs', 'App\Pixelforge', 'Inertia']))
            ->because('a repository fetches and stores; it decides nothing'),

        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Events'))
            ->should(new IsFinal)
            ->because('an event is a plain statement of what happened'),

        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Jobs'))
            ->should(new Implement('Illuminate\Contracts\Queue\ShouldQueue'))
            ->because('every job runs on the queue, never inside a web request'),

        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Console\Commands'))
            ->should(new Extend('Illuminate\Console\Command'))
            ->because('a class in the commands folder is a command'),

        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Models'))
            ->should(new Extend('Illuminate\Database\Eloquent\Model', 'Illuminate\Foundation\Auth\User'))
            ->because('a class in the models folder is a model'),

        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Enums'))
            ->should(new IsEnum)
            ->because('the enums folder holds enums'),

        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Support'))
            ->should(new IsFinal)
            ->because('money arithmetic must not be changed by a subclass'),

        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Support'))
            ->should(new NotHaveDependencyOutsideNamespace('App\Support', ['InvalidArgumentException']))
            ->because('money arithmetic depends on nothing, not even the framework'),

        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Pixelforge'))
            ->should(new NotDependsOnTheseNamespaces(['App\Models', 'App\Http', 'App\Jobs', 'App\Services', 'Illuminate\Database']))
            ->because('the Pixelforge layer only knows Pixelforge: no database, no requests, no jobs'),

        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Models', 'App\Services', 'App\Repositories', 'App\Pixelforge', 'App\Support', 'App\Events', 'App\Listeners'))
            ->should(new NotDependsOnTheseNamespaces(['App\Http', 'Inertia']))
            ->because('nothing below the HTTP layer knows about requests, responses or pages'),
    );
};
