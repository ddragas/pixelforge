# Pixelforge Studio

A small Laravel + Inertia + Vue application that resells image generation from the Pixelforge API. A user buys credit, describes an image, sees what it will cost before sending, and is charged the provider's cost plus 25 % when the image is finished. If it fails, they pay nothing.

The application is deliberately small. The work is in getting the money and the asynchronous lifecycle exactly right.

## What it does

| Page | What happens there |
| --- | --- |
| `/generate` | The form. A price range (lowest, most likely, highest) appears as you type and is recalculated whenever the request or your credit changes. |
| `/history` | Your generations, with live status. The page asks again every few seconds while anything is still running. |
| `/admin/report` | For operators: charges, provider cost and profit per UTC day and per user, compared day by day with Pixelforge's own `/v1/usage` figures. |
| `POST /webhooks/pixelforge` | Where Pixelforge reports progress. |

Buying credit is a pretend purchase: the balance goes up, no payment is taken.

## Decisions worth knowing about

### Money is whole nanodollars, never a float

Pixelforge prices are dollars per million tokens with up to three decimals, so one token costs a whole number of nanodollars (`"12.50"` per million is 12,500 nanodollars per token). Every amount in the database and in PHP is an integer number of nanodollars. Amounts go to the browser as exact decimal strings and the browser never does arithmetic on them. A rate finer than a nanodollar per token is refused loudly rather than rounded quietly.

The provider cost we calculate is checked against Pixelforge's own figure: for a real generation ours was `0.057657500` and `/v1/usage` reported `0.057657500`.

### The 25 % markup rounds up

A quarter on top of an odd number of nanodollars is not a whole nanodollar. It is rounded up, so the user is never charged less than cost plus markup and never more than one nanodollar above it: `intdiv(cost * 125 + 99, 100)`.

### The highest possible price is set aside, the real price is charged

Output and reference-image tokens are known in advance. Text tokens are known to within the published variance and reasoning tokens within a range per quality. So the price before sending is a range. When a generation is submitted the highest price is set aside, which means a user cannot spend the same credit twice on generations running at the same time. When it finishes, the real cost is charged and the rest is released; when it fails, all of it is released.

### One door for every piece of news about a generation

Whatever we learn from Pixelforge, whether in the answer to our own request, in a webhook or by polling, goes through `GenerationLifecycle::apply()`. It locks the generation, and applies the news only if it moves the generation forward. That one rule covers:

- the same event delivered twice,
- events arriving out of order (a late `processing` after `succeeded` changes nothing),
- a webhook arriving before our own create request has returned,
- a webhook and the polling job reporting the same thing at the same moment.

Charging happens inside the same transaction that marks the generation as succeeded. As a last line of defence the ledger has a unique index on the generation, so the database itself refuses a second charge.

### Webhooks are verified, stored, answered, then processed

The signature is checked against the raw body before anything is decoded, with a constant-time comparison, and timestamps older than five minutes are refused. The event is stored under its id, which is the primary key, so a duplicate delivery inserts nothing. Pixelforge gets its `204` straight away and a queued job does the work.

### Submitting survives Pixelforge being busy

Pixelforge allows 60 requests a minute and three generations at once, and is sometimes overloaded. A refusal of that kind creates nothing, so the job waits as long as the `Retry-After` header asks (and a little longer each time) and tries again, for up to five minutes. Our own id is sent as the `Idempotency-Key`, so a retry after a lost answer returns the same generation instead of creating and billing a second one.

### Lost webhooks are not fatal

Every minute a scheduled command asks Pixelforge directly about generations it has accepted but that we have heard nothing about for a minute. Nothing stays "processing" for ever.

## How the code is laid out

```
app/
  Http/           Controllers, form requests, resources. HTTP only.
  Services/       The logic: GenerationOrders, Credits, GenerationLifecycle, OperatorReport.
  Repositories/   The only code that queries or writes the database. One per model.
  Pixelforge/     The only code that talks to Pixelforge, and the pricing rules. Knows nothing about our database.
  Jobs/           SubmitGeneration, ProcessWebhookEvent.
  Events/         GenerationSucceeded, GenerationFailed, announced after commit.
  Listeners/      Log the outcome; raise the alarm if our API key is rejected.
  Models/         Table definitions, casts, relations.
  Support/Money   Nanodollars to and from decimal strings.
```

Controllers handle HTTP, services hold the logic and open the transactions, repositories fetch and store, models describe tables. Events are used for consequences that are not money. Charging is not a listener, on purpose: it must be in the same transaction as the status change.

These rules are enforced, not just written down:

- **Deptrac** (`deptrac.yaml`) checks which layer may depend on which.
- **PHPArkitect** (`phparkitect.php`) checks naming and what each kind of class may be.
- **Pest architecture tests** check, among other things, that only the Pixelforge client makes HTTP calls and that the ledger is written in one place.
- A test that reads the source checks that nothing outside the repositories runs a query or writes a row.

The sign-in, registration and settings pages are the Laravel Vue starter kit, left as they came.

## Running it

Requirements: PHP 8.4, Composer, Node 22, PostgreSQL, Redis.

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
# set DB_*, REDIS_*, PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET in .env
php artisan migrate

php artisan serve          # the site
php artisan queue:work     # submits generations and processes webhooks
php artisan schedule:work  # the once-a-minute check for lost webhooks
```

Register an account, then let it see the operators' report:

```bash
php artisan app:make-operator you@example.com
```

Use one Pixelforge API key per environment. Each key has its own webhook URL, signing secret and usage report, and the operators' report compares against the usage of the key it runs with.

## Tests

```bash
composer check           # everything below except mutation and load
composer lint            # Pint, Rector (dry run)
composer analyse         # PHPStan level 8 (Larastan), PHPMD, Deptrac, PHPArkitect
composer test            # unit, feature, architecture, security, regression, performance
composer test:browser    # the user journey in a real browser (needs npm run build)
composer test:mutation   # mutation testing (needs pcov or Xdebug)
composer test:load       # webhook storm against the running app
```

| Kind | Where | What it shows |
| --- | --- | --- |
| Unit | `tests/Unit` | The money arithmetic, with expected figures worked out by hand from the documented rates. |
| Feature / integration | `tests/Feature` | Each rule above, through the real routes, jobs and database, with Pixelforge faked at the HTTP boundary. |
| Browser (end to end) | `tests/Browser` | A new user goes from an empty wallet to a finished, correctly charged image; failures; a phone-sized screen. |
| Regression | `tests/Feature/RegressionTest.php` | One test for each fault found while building this, named after what went wrong. |
| Security | `tests/Feature/SecurityTest.php` | Other users' data, operator-only pages, mass assignment, forged, altered, replayed and unsigned webhooks, rate limits. |
| Performance | `tests/Feature/PerformanceTest.php` | Pages run the same number of queries for one row as for many; the webhook answers without calling Pixelforge. |
| Architecture | `tests/Architecture`, Deptrac, PHPArkitect | The layering described above. |
| Mutation | `composer test:mutation` | 85.5 % of mutations to our own code are caught. Most of the rest are equivalent: an `(int)` cast on a value that is already an integer. |
| Load | `tests/Load/webhook-storm.php` | See below. |

The feature tests run on SQLite in memory by default, for speed, and the pipeline runs them again on PostgreSQL.

### The load test

It runs against the real stack: the web server, PostgreSQL, Redis and several queue workers. For 200 generations it delivers each of three events twice, in random order, twenty at a time, then checks the books. On the development machine, with four workers: all 1,200 deliveries answered `204`, every generation charged exactly once, the balance down by exactly the sum of the charges, no credit left set aside.

It is a test of correctness under concurrency, not a benchmark. The response times it prints reflect PHP's single-process development server.

## Known gaps

- **Front-end type checking.** `vue-tsc` reports errors in the starter kit's own components, and did before any of this code was added. It is not part of the pipeline. The pages themselves are covered by the browser tests.
- **Two moderate advisories in development tooling** (`glob` and `minimatch`, through `vue-tsc`). Fixing them needs a breaking upgrade of that tool. Nothing that reaches the browser is affected; `npm audit --omit=dev --audit-level=high` is clean and is checked in the pipeline.
- **Infection** does not run Pest tests in its current version, so mutation testing uses Pest's own mutation plugin.
- **Buying credit is fake**, as the brief allows. A real purchase would be its own ledger entry type behind a payment provider's webhook, handled with the same store-then-process approach as Pixelforge's.
- **Image links expire.** The page says so when one has; it does not copy images to our own storage.

## With more time

- Push status to the browser over WebSockets instead of polling.
- Copy finished images to our own storage, so they outlive Pixelforge's signed links.
- A daily job that runs the operators' comparison and alerts when a day does not match, instead of waiting for someone to open the report.
