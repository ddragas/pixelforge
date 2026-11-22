<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Only repositories talk to the database.
 *
 * Models are passed around everywhere as plain values, so this cannot be
 * checked by looking at which classes use which. It is checked by reading
 * the source: outside the repositories, nothing may run a query or write
 * a row. The sign-in and settings code that came with the starter kit is
 * left as it came.
 */

const QUERIES_AND_WRITES = [
    '::query(', '::where', '::find', '::create(', '::insert', '::update(', '::destroy(', '::count(', '::sum(',
    '->save(', '->update(', '->delete(', '->refresh(', '->fresh(', '->forceFill(',
    '->lockForUpdate(', '->insertOrIgnore(', '->generations()', '->creditTransactions()',
    'DB::table(', 'DB::select(', 'DB::statement(',
];

// A call on a repository, such as $this->users->save($user), is the allowed way.
const CALLS_A_REPOSITORY = '/\$(this->)?(users|generations|events|ledger)->/';

it('runs queries and writes rows only inside the repositories', function (): void {
    $files = Finder::create()->files()->name('*.php')->in(dirname(__DIR__, 2).'/app')
        ->exclude(['Repositories', 'Models', 'Http/Controllers/Auth', 'Http/Controllers/Settings', 'Http/Requests']);

    $offences = [];

    foreach ($files as $file) {
        foreach (explode("\n", $file->getContents()) as $number => $line) {
            foreach (QUERIES_AND_WRITES as $needle) {
                if (str_contains($line, $needle) && preg_match(CALLS_A_REPOSITORY, $line) !== 1) {
                    $offences[] = $file->getRelativePathname().':'.($number + 1).'  '.trim($line);
                }
            }
        }
    }

    expect($offences)->toBe([]);
});

it('has a repository for every model', function (): void {
    foreach (Finder::create()->files()->name('*.php')->in(dirname(__DIR__, 2).'/app/Models') as $model) {
        expect(dirname(__DIR__, 2).'/app/Repositories/'.$model->getBasename('.php').'Repository.php')->toBeFile();
    }
});
