# CLAUDE.md

## Project purpose
API-driven product sync from 2 mock supplier APIs (DummyJSON, FakeStore) into a
local MySQL store via queued, delta (hash-based) sync jobs. This is a technical
case submission — see README.md for the full case requirements, API docs,
setup instructions, and grading criteria.

## Tech stack
- Laravel 10.x, PHP 8.2, MySQL 8, Redis 7 (queue + cache + Horizon)
- Laravel Horizon for queue dashboard/monitoring/manual retry UI
- Predis (pure-PHP client, not the phpredis extension) — keeps the Docker
  image lean, no C-extension build needed. **`REDIS_CLIENT=predis` must be
  set in `.env`** — without it Laravel defaults to `phpredis` and every
  Redis call (cache, queue, health check) fails with `Class "Redis" not
  found` since the C-extension is deliberately not installed.
- Laravel Reverb (WebSocket broadcasting) for real-time dashboard updates —
  runs in its own `reverb` container, proxied by nginx under `/app/`
  (`docker/nginx/conf.d/default.conf`) so the browser only ever talks to
  one host/port. No SSL for now (`REVERB_SCHEME=http`, plain `ws://`).
- No frontend build step: Blade + vanilla JS + Tailwind CDN. `package.json`/
  `vite.config.js` are unused leftovers from the Laravel skeleton.
- Tests run against a dedicated `db_test` MySQL container (`phpunit.xml` →
  `DB_HOST=db_test`, host port 3307), not SQLite — same engine as
  production so engine-specific behavior isn't missed by the test suite
- Horizon and the scheduler (`schedule:work`) run in one `worker` container
  under `supervisord` (`docker/supervisord/{supervisord.conf,horizon.conf}`),
  not as two separate containers — job uniqueness is already solved at the
  `ShouldBeUnique` level, so splitting them into separate containers buys
  nothing

## Architecture map
- `app/Contracts/ProviderClientInterface.php` — provider abstraction
  (`fetchAll()`, `fetchOne()`)
- `app/Services/Providers/{DummyJson,FakeStore}Provider.php` — implementations
- `app/Services/Providers/ProviderFactory.php` — resolves a `ProviderType` to
  its client, bound in `AppServiceProvider`
- `app/Services/Sync/HashService.php` — sha256 canonical hash of
  `{name, price, stock, description}`
- `app/Services/Sync/DeltaSyncService.php` — added/updated/deleted logic,
  wrapped in `DB::transaction()`
- `app/Services/Sync/ThrottledHttpClient.php` — 5rps pacing + 429 backoff +
  5-consecutive-failure circuit breaker
- `app/Jobs/SyncProviderJob.php` — `ShouldBeUnique` (per-provider cache lock,
  15 min TTL), `tries = 3`, `backoff = [1, 2, 4]`, dispatched onto the single
  `product-sync` queue (provider-agnostic — not split per provider; see
  `config/horizon.php`'s `product-sync-supervisor`)
- `app/Services/Alerts/AlertService.php` — 4 threshold checks, 5 min
  cache-throttled, structured JSON to `storage/logs/alerts.log`
- `app/Http/Traits/ApiResponseTrait.php` — `{success, data, meta, message}` /
  `{success: false, error: {code, message}}` envelope

## Key conventions
- Every API controller uses `ApiResponseTrait` — never return
  `response()->json()` ad hoc.
- Idempotency = `unique(provider_type, external_id)` constraint + transaction
  + hash no-op skip. Never bypass the transaction in `DeltaSyncService`.
- Job uniqueness key is the provider value only (`"dummyjson"`/`"fakestore"`),
  not job class + params — one lock per provider, not per job invocation.
- Hash excludes `external_id`/`provider_type` (identity, not content) and any
  provider-specific volatile fields (ratings, images).
- `failed_jobs` is Laravel's native table (+ one added `retry_count` column)
  — do not create a parallel DLQ table; retries go through `queue:retry`.
- Products are soft-deleted (`deleted_at`), not hard-deleted, when absent from
  a provider's latest sync — preserves history and allows restore if the
  product reappears later.

## Where to look for more
- Full case requirements, DB design rationale, hash/idempotency/rate-limiting
  write-ups, API docs, install steps: `README.md`
- Config surface (rate limit, alert thresholds, provider list): `config/sync.php`
- `.env.example` documents every required environment variable
