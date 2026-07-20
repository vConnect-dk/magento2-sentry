# AGENTS.md

## What this is

`JustBetter_Sentry` — Magento 2 module wiring Sentry error logging, tracing/profiling, and JS session-replay into Magento. This checkout is a **vConnect fork** of upstream `justbetter/magento2-sentry` (origin `vConnect-dk/magento2-sentry`). PSR-4 root namespace `JustBetter\Sentry\` maps to the repo root (module lives at its own root, not under `app/code`).

- Fork's headline addition: the **resilience layer** (commit `97b6dbb`) — async MQ delivery + circuit breaker so a slow/down Sentry can never block a shopper request. Keep this in mind before touching `Model/Transport/`, `Model/CircuitBreaker.php`, or `Model/Queue/`.
- When editing, preserve upstream compatibility where practical; fork-specific code is the resilience layer, not the base logging path.

## Commands

Static analysis and codestyle are the only CI gates (`.github/workflows/analyse.yml`, `phpcs.yml`), run on PHP 8.2–8.5 × prefer-lowest/prefer-stable.

```bash
composer run analyse    # PHPStan level 8 (config: phpstan.neon)
composer run phpcs      # Magento2 coding standard (LineLength excluded)
composer run phpcbf     # auto-fix codestyle
composer run codestyle  # phpcbf then phpcs
composer run rector     # rector.php ruleset
```

Tests live in `Test/Unit/` (PHPUnit) but the repo ships **no `phpunit.xml`** and CI does not run them. Run directly:

```bash
vendor/bin/phpunit Test/Unit                                   # all unit tests
vendor/bin/phpunit Test/Unit/Model/CircuitBreakerTest.php      # single file
vendor/bin/phpunit --filter testOpensAfterThreshold Test/Unit  # single test
```

## Architecture

### Two entry paths into Sentry
- **Fatal/uncaught errors** → `Plugin/GlobalExceptionCatcher.php` (plugin on `AppInterface` for web and `Console\Command\Command` for CLI). Initializes the SDK via `Model/SentryInteraction.php` and captures.
- **Log records** → `Plugin/MonologPlugin.php` (plugin on `Monolog\Logger`) routes records at/above the configured level to `Logger/Handler/Sentry.php` → `Model/SentryLog.php`.

`Model/SentryInteraction.php` is the SDK bootstrap: builds the Sentry client, and injects the fork's `ResilientTransportFactory` as the transport. `Helper/Data.php` is the single config accessor (DSN, log level, sample rates, and all resilience toggles).

### Resilience layer (fork-specific — the important part)
Outbound delivery is abstracted behind `Model/Transport/ResilientTransport.php` (implements Sentry `TransportInterface`), chosen per-request:
- **Async mode** (`async_sending_enabled`): serialize the envelope *immediately* (so `sent_at`/event timestamps are frozen at capture time), publish to MQ topic `justbetter.sentry.event.send`. The `justbetter.sentry.event` consumer (`Model/Queue/Consumer/SentryEventConsumer.php`) later ships it over HTTP via `Model/Transport/EnvelopeSender.php`.
- **Sync mode + circuit breaker** (`Model/CircuitBreaker.php`): short-timeout HTTP; after N consecutive failures the circuit *opens* and calls fail fast (states closed→open→half-open→closed, persisted in Magento cache). With `async_fallback_on_circuit_open`, events queue instead of being dropped.
- Re-entrancy guard (`$sending` flag in `ResilientTransport`) prevents a capture triggered *during* publishing/logging from recursing.

MQ wiring: `etc/communication.xml`, `etc/queue_topology.xml`, `etc/queue_consumer.xml`, `etc/queue_publisher.xml`. Run the consumer in async mode:
```bash
bin/magento queue:consumers:start justbetter.sentry.event
```
All resilience config keys are documented in README.md → "Resilient delivery" table; admin fields in `etc/adminhtml/system.xml` (search `async_`/`circuit_breaker_`). Everything is DI-wired through `etc/di.xml` with `\Proxy` suffixes to keep bootstrap lazy.

### Tracing / Profiling
`Plugin/Profiling/*` plugins instrument events, template rendering, DB queries, cache, and message-queue enqueue/consume into Sentry spans. `Model/SentryPerformance.php` + `PerformanceTracingDto.php` drive sampling (`traces_sample_rate`, `traces_sample_rate_cli`, `profiles_sample_rate`). `Plugin/CronScheduleCheckIn.php` reports Sentry cron check-ins.

### Frontend / CSP / JS replay
`Block/SentryScript.php` (+ `view/frontend`) injects the browser SDK for JS error + session replay. `Model/Collector/SentryRelatedCspCollector.php` and `Plugin/CspModeConfigManagerPlugin.php` add the required Content-Security-Policy entries and `report-uri` so Sentry hosts aren't blocked. `Plugin/LogrocketCustomerInfo.php` enriches the customer section data.

## Conventions

- **DI uses `\Proxy` deliberately** for the resilience/logging graph — do not "simplify" by removing them; they keep the SDK/transport out of the early bootstrap and off cold paths.
- Plugin naming follows the DI `name` attribute (e.g. `sentry-profiling-db-queries`); keep names stable — some are referenced across configs.
- PHPStan level 8 must pass. `Logger/Handler/Sentry.php` is excluded (`phpstan.neon`) due to Monolog handler signature variance across versions — keep changes there compatible with the `monolog >=2.7|^3.0` range.
- README.md is the user-facing source of truth for config keys; update it when adding/renaming a config field.
