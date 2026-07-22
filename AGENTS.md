# AGENTS.md

## What this is

`JustBetter_Sentry` — Magento 2 module. Wires Sentry error logging, tracing/profiling, JS session-replay into Magento. Checkout = **vConnect fork** of upstream `justbetter/magento2-sentry` (origin `vConnect-dk/magento2-sentry`). PSR-4 root namespace `JustBetter\Sentry\` maps to repo root (module lives at own root, not under `app/code`).

- Fork's headline add: **resilience layer** — async MQ delivery + circuit breaker so slow/down Sentry never blocks shopper request. Mind this before touching `Model/Transport/`, `Model/CircuitBreaker.php`, or `Model/Queue/`.
- Fork has diverged from upstream beyond resilience layer (e.g. cron check-in monitor-config resolution). Editing: keep upstream compat where practical, but don't assume resilience layer is the only fork-specific code.

## Commands

Static analysis + codestyle only CI gates (`.github/workflows/analyse.yml`, `phpcs.yml`), run PHP 8.2–8.5 × prefer-lowest/prefer-stable.

```bash
composer run analyse    # PHPStan level 8 (config: phpstan.neon)
composer run phpcs      # Magento2 coding standard (LineLength excluded)
composer run phpcbf     # auto-fix codestyle
composer run codestyle  # phpcbf then phpcs
composer run rector     # rector.php ruleset
```

Tests in `Test/Unit/` (PHPUnit) but repo ships **no `phpunit.xml`**, CI don't run them. Run direct:

```bash
vendor/bin/phpunit Test/Unit                                   # all unit tests
vendor/bin/phpunit Test/Unit/Model/CircuitBreakerTest.php      # single file
vendor/bin/phpunit --filter testOpensAfterThreshold Test/Unit  # single test
```

## Architecture

### Two entry paths into Sentry
- **Fatal/uncaught errors** → `Plugin/GlobalExceptionCatcher.php` (plugin on `AppInterface` for web, `Console\Command\Command` for CLI). Inits SDK via `Model/SentryInteraction.php`, captures.
- **Log records** → `Plugin/MonologPlugin.php` (plugin on `Monolog\Logger`) routes records at/above configured level to `Logger/Handler/Sentry.php` → `Model/SentryLog.php`.

`Model/SentryInteraction.php` = SDK bootstrap: builds Sentry client, injects fork's `ResilientTransportFactory` as transport. `Helper/Data.php` = single config accessor (DSN, log level, sample rates, all resilience toggles).

### Resilience layer (fork-specific — important part)
Outbound delivery abstracted behind `Model/Transport/ResilientTransport.php` (implements Sentry `TransportInterface`), chosen per-request:
- **Async mode** (`async_sending_enabled`): serialize envelope *immediately* (freezes `sent_at`/event timestamps at capture time), publish to MQ topic `justbetter.sentry.event.send`. `justbetter.sentry.event` consumer (`Model/Queue/Consumer/SentryEventConsumer.php`) ships it later over HTTP via `Model/Transport/EnvelopeSender.php`.
- **Sync mode + circuit breaker** (`Model/CircuitBreaker.php`): short-timeout HTTP; after N consecutive fails circuit *opens*, calls fail fast (states closed→open→half-open→closed, persisted in Magento cache). Circuit open or delivery fails → events dropped immediately.
- Re-entrancy guard (`$sending` flag in `ResilientTransport`) stops capture triggered *during* publishing/logging from recursing.

MQ wiring: `etc/communication.xml`, `etc/queue_topology.xml`, `etc/queue_consumer.xml`, `etc/queue_publisher.xml`. Run consumer in async mode:
```bash
bin/magento queue:consumers:start justbetter.sentry.event
```
All resilience config keys documented in README.md → "Resilient delivery" table; admin fields in `etc/adminhtml/system.xml` (search `async_`/`circuit_breaker_`). Everything DI-wired via `etc/di.xml` with `\Proxy` suffixes, keeps bootstrap lazy.

### Tracing / Profiling
`Plugin/Profiling/*` plugins instrument events, template rendering, DB queries, cache, message-queue enqueue/consume into Sentry spans. `Model/SentryPerformance.php` + `PerformanceTracingDto.php` drive sampling (`traces_sample_rate`, `traces_sample_rate_cli`, `profiles_sample_rate`). `Plugin/CronScheduleCheckIn.php` reports Sentry cron check-ins.

### Frontend / CSP / JS replay
`Block/SentryScript.php` (+ `view/frontend`) injects browser SDK for JS error + session replay. `Model/Collector/SentryRelatedCspCollector.php` + `Plugin/CspModeConfigManagerPlugin.php` add required Content-Security-Policy entries + `report-uri` so Sentry hosts stay unblocked. `Plugin/LogrocketCustomerInfo.php` enriches customer section data.

## Conventions

- **Keep existing `\Proxy` wiring** in resilience/logging graph (`Model/Transport/`, `Model/Queue/`, `SentryInteraction`) — don't "simplify" by removing. Keeps SDK/transport out of early bootstrap, off cold paths.
- **New DI: proxy only when it earns keep**: dependency must be skippable on disabled/gated path (e.g. Sentry inactive) *and* not already forced real elsewhere in same object graph. Don't default-proxy core/ubiquitous framework services (`ScopeConfigInterface`, `Psr\Log\LoggerInterface`, `TimezoneInterface`, ...) — cheap, usually already resolved by something else in request, wrapping just adds indirection, no payoff. Example: `Model/SentryCron.php` proxies `Magento\Cron\Model\ConfigInterface` (narrow-scope, real parsing cost, skipped whenever `sendScheduleStatus()`'s early-return gate fires) but resolves `scopeConfig`/`timezone`/`logger` normal — `Helper\Data` (also injected there) already forces real `ScopeConfigInterface` via own `Context`, proxying twice bought nothing.
- Plugin naming follows DI `name` attribute (e.g. `sentry-profiling-db-queries`); keep names stable — some referenced across configs.
- PHPStan level 8 must pass. `Logger/Handler/Sentry.php` excluded (`phpstan.neon`) due to Monolog handler signature variance across versions — keep changes there compatible with `monolog >=2.7|^3.0` range.
- README.md = user-facing source of truth for config keys; update when adding/renaming config field.