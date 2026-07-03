---
name: feature
description: Scaffold a new Laravel 10.x feature (migration, model, service, controller, routes, tests) following this project's SOLID-driven architecture.
argument-hint: [feature name and short description]
arguments: [feature]
disable-model-invocation: true
---

You are scaffolding a new feature for this Laravel 10.x project: **"$feature"**.

Read `CLAUDE.md` at the repo root first — it documents this project's existing
architecture (Contracts/Services/DTOs split, `ApiResponseTrait` envelope,
soft-delete-for-deletion convention, enum usage, etc.). Every file you create
here must fit that architecture, not invent a parallel one.

## 1. Clarify scope before writing code

Don't assume. If any of these are unclear from "$feature", ask the user
(prefer one focused question over several):
- Is this a CRUD resource exposed over the API, an internal-only service, or a
  background-job-driven feature (like the existing product sync)?
- Does it need authorization (a Policy), or is it open to any authenticated/
  unauthenticated caller?
- Does persistence need anything beyond a plain Eloquent model (e.g. multiple
  data sources, an external API behind it, complex query composition)? If not,
  do **not** add a Repository layer just for ceremony — Eloquent models behind
  a thin Service is the default for this codebase (see `DeltaSyncService`
  talking to `Product` directly).

## 2. File-by-file checklist (map each to why it exists — SOLID, not ceremony)

Only create the files this specific feature actually needs. Skip a layer and
say why, rather than padding out every category below "for completeness."

| Layer | Path pattern | Responsibility | SOLID principle it serves |
|---|---|---|---|
| Migration | `database/migrations/<timestamp>_create_<table>_table.php` | Schema: correct column types, `unique()`/`index()` on lookup columns, `foreignId()->constrained()` for relations | — |
| Enum | `app/Enums/<Name>.php` | Backed `enum ... : string` for any fixed value set (statuses, types) instead of magic strings | OCP — new cases added without touching consumers that `match()` exhaustively |
| Model | `app/Models/<Feature>.php` | `$fillable`/`$casts`, relationships, query scopes, accessors. **No business logic beyond querying/shaping its own data.** | SRP |
| Contract | `app/Contracts/<Feature>ServiceInterface.php` | Only add this if the service has (or will realistically have) more than one implementation, or needs to be mocked in a controller/feature test without hitting real logic | DIP, ISP — keep it small and focused, not a god-interface |
| Service | `app/Services/<Area>/<Feature>Service.php` | Business logic / orchestration. Depends on contracts, not concrete classes, via constructor injection | SRP, DIP |
| DTO | `app/DTOs/<Feature>Data.php` | `readonly` typed data carried between layers instead of raw arrays — mirrors `ProviderProductDTO`/`SyncResult` already in this codebase | — |
| Form Request | `app/Http/Requests/<Action><Feature>Request.php` | Validation + `authorize()`. Controllers never validate inline. | SRP |
| Controller | `app/Http/Controllers/Api/<Feature>Controller.php` | Thin: request in → service call → `ApiResponseTrait::success()`/`error()` out. One action per method. No business logic in the controller. | SRP |
| API Resource | `app/Http/Resources/<Feature>Resource.php` | Response shaping — never return a raw Eloquent model from a controller | SRP |
| Policy | `app/Policies/<Feature>Policy.php` | Only if authorization is actually required; register in `AuthServiceProvider::$policies` | SRP |
| Routes | `routes/api.php` | Group under a sensible prefix, resourceful naming (`index`, `store`, `show`, `update`, `destroy`) | — |
| Binding | `app/Providers/AppServiceProvider.php` (`register()`) | `$this->app->bind(FooServiceInterface::class, FooService::class)` — only needed if you added a Contract | DIP |
| Tests | `tests/Unit/Services/<Feature>ServiceTest.php` + `tests/Feature/Api/<Feature>ControllerTest.php` | Unit test the service in isolation (mock the contract's dependencies); feature test the HTTP surface end-to-end (against the dedicated `db_test` MySQL container, per `phpunit.xml` — no SQLite anywhere in this project) | — |

## 3. Conventions to match (already decided in this repo — don't relitigate)

- Every controller uses `ApiResponseTrait` (`app/Http/Traits/ApiResponseTrait.php`)
  for the `{success, data, meta, message}` / `{success:false, error:{code,message}}`
  envelope. Never `response()->json()` ad hoc in a new controller.
- "Deleted" almost always means soft-deleted (`deleted_at`) in this codebase,
  not a hard delete, unless the feature has an explicit reason to differ —
  state that reason if you deviate.
- Multi-row writes that must succeed-or-fail together go in `DB::transaction()`.
- Prefer constructor property promotion and typed properties (PHP 8.1+)
  everywhere — this is a fresh Laravel 10 codebase, no legacy PHP 7 style to
  match.
- Enums over string constants for any closed set of values.
- **DRY:** before writing a new Form Request rule, query, response shape, or
  threshold constant, check whether this feature is duplicating something
  that already exists elsewhere in the codebase (another Request's rule,
  another Resource's field list, a magic number that belongs in
  `config/*.php`). If it's genuinely the same piece of knowledge, extract a
  shared home for it (a trait, a base class, a config value, a query scope)
  instead of copy-pasting. Don't force this for two things that only *look*
  similar but change for unrelated reasons — that's a false DRY violation.

## 4. Before calling it done

1. `php artisan test --filter=<Feature>` — new tests pass.
2. `./vendor/bin/pint --dirty` — code style matches the rest of the repo
   (Pint is already a dev dependency).
3. `php artisan route:list --path=<feature-prefix>` — routes registered as
   expected, no accidental duplicate/overlapping routes.
4. Re-read the file-by-file table above and state, in one line each, which
   SOLID principle each new class satisfies and which layer (if any) you
   deliberately skipped and why. If you can't justify a layer's existence in
   one sentence, don't create it.
5. Re-scan the diff for DRY violations against the *existing* codebase, not
   just within the new files — a repeated validation rule, magic number, or
   response shape copied from another feature instead of reused.
