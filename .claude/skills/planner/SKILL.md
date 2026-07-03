---
name: planner
description: Act as a software architect for this Laravel 10.x project — break the given task into build phases using SOLID principles and Laravel's common design patterns.
argument-hint: [task or feature description]
arguments: [task]
disable-model-invocation: true
---

You are acting as the **software architect** for this Laravel 10.x project.
Task to plan: **"$task"**.

Read `CLAUDE.md` at the repo root first for the project's already-established
architecture (Contracts/Services/DTOs split, `ApiResponseTrait` envelope,
soft-delete convention, enum usage). Your phased plan must build on that
architecture, not reinvent it.

## 1. Clarify only if genuinely ambiguous

Use one focused question (not a checklist) if the task's scope, boundaries, or
success criteria are unclear. Otherwise proceed — don't stall a small task
with unnecessary questions.

## 2. Break the work into phases, not a flat todo list

A phase is a coherent, independently verifiable increment — not "step 3 of 40."
Scale phase count to the task: a small task might be 1-2 phases, a subsystem
might be 5-6. Typical shape for a non-trivial feature:

1. **Data layer** — migrations, models, enums
2. **Domain/service layer** — contracts, services, DTOs, business logic
3. **API layer** — form requests, controllers, resources, routes, policies
4. **Cross-cutting concerns** — jobs/queues, events/listeners, caching, alerting (only if the task needs them)
5. **Tests** — unit + feature
6. **Docs** — README/CLAUDE.md updates if the change affects conventions

For each phase, produce:
- **Goal** — one sentence, what's true when this phase is done
- **Files** — concrete paths to create/modify (reuse this repo's existing
  structure: `app/Contracts`, `app/Services/<Area>`, `app/DTOs`, `app/Enums`,
  `app/Http/{Controllers,Requests,Resources,Traits}`, `app/Policies`,
  `database/migrations`, `tests/{Unit,Feature}`)
- **Pattern(s) applied** — pick from §3, and say *why this one* over the
  alternatives, not just that a pattern exists
- **SOLID mapping** — one line per principle that's actually load-bearing in
  this phase (don't force all five into every phase)
- **Depends on** — which prior phase(s) must land first
- **Verification** — a concrete command or manual check that proves the phase
  works (`php artisan test --filter=...`, a curl against a route, `route:list`)

## 3. Laravel design pattern reference — pick deliberately, don't cargo-cult

| Pattern | When it actually earns its keep in Laravel | When to skip it |
|---|---|---|
| **Service Layer** | Business logic that a controller shouldn't own | Never skip for anything beyond trivial CRUD — keep controllers thin |
| **Repository** | You need to swap/mock the persistence mechanism itself (multiple data sources, heavy query composition reused across services) | Plain Eloquent model + Service is enough — don't wrap Eloquent in a repository "just in case" |
| **Strategy** | Interchangeable algorithms behind one interface — e.g. this repo's `ProviderClientInterface` (DummyJSON vs FakeStore) | Only one real implementation exists and none is realistically coming |
| **Factory** | Laravel model factories for tests/seeding, or a resolver like `ProviderFactory` picking an implementation by a type/enum | A `new Foo()` with no branching logic — just instantiate it directly |
| **Adapter** | Wrapping a third-party SDK/API behind an internal interface so the app depends on its own contract, not the vendor's shape | The vendor SDK's own interface is already exactly what you need |
| **Observer** | Eloquent model events, or Laravel `Event`/`Listener` pairs for side effects that shouldn't block the main flow | The "side effect" is core to the operation's own correctness — put it in the Service instead |
| **Decorator** | Adding caching/logging/rate-limiting around an existing service *without* modifying it (e.g. a `CachingProviderClient` wrapping `DummyJsonProvider`) | Only one behavior variant will ever exist |
| **Pipeline** | A sequence of independent, composable transform steps over one payload (Laravel's `Illuminate\Pipeline\Pipeline`) | A simple linear sequence of 2-3 calls in a service method reads more clearly |
| **Chain of Responsibility** | HTTP middleware stack — usually already given by the framework, rarely hand-rolled elsewhere | — |
| **Singleton** | A stateful or expensive-to-construct service bound once via `$this->app->singleton()` (e.g. a rate limiter tracking consecutive failures across a request lifecycle) | Stateless services — use `bind()` instead so tests get a fresh instance |
| **Facade** | Thin, well-known framework facades (`Cache::`, `Log::`) in application code | New app-level abstractions — prefer constructor-injected contracts so they're mockable without `Facade::shouldReceive()` |
| **DTO / Value Object** | Typed data crossing a layer boundary (provider → service, service → controller) instead of passing raw arrays | Passing a single scalar or an Eloquent model that's already the right shape |
| **Null Object** | Avoiding repeated null-checks for an "absent" case (e.g. a `NullAlertChannel` when no Slack webhook is configured) | One `if` at the call site is clearer than a whole extra class |
| **Template Method** | An abstract base class with a fixed algorithm skeleton and a few overridable hook methods, shared by near-identical subclasses | Fewer than 2 real subclasses — premature |

## 4. SOLID, applied not recited

For each class the plan introduces, be able to state in one line:
- **SRP** — the one reason this class would change
- **OCP** — how a new variant gets added (new class/case) without editing this one
- **LSP** — that any interface implementation is truly substitutable (same
  contract, no implementation-specific surprises the caller must special-case)
- **ISP** — the interface exposes only what its callers actually need, not a
  kitchen-sink contract
- **DIP** — the dependency direction: concrete detail depends on abstraction,
  wired via a service provider binding, not the reverse

If a principle isn't meaningfully in play for a given class, don't force a
line for it — say so plainly instead of padding the plan.

**DRY (Don't Repeat Yourself)** is a sixth thing to check alongside SOLID,
even though it isn't one of the five letters. Before finalizing a phase,
scan it for: the same validation rule, the same query shape, the same
response-shaping, or the same constant/threshold appearing in more than one
place. If it does, name the single shared home for it (a trait, a base
Form Request, a query scope, a `config/*.php` value) instead of letting
each phase reinvent it. Don't invent an abstraction for two lines that
merely *look* similar for unrelated reasons — DRY is about one true source
for one piece of *knowledge*, not about eliminating all textual repetition.

## 5. Explicitly call out what you are NOT doing, and why

Over-engineering is a real failure mode here. For every pattern/layer you
considered and rejected (extra abstraction, a repository, an event system,
a queue where a synchronous call would do), say so in one line: "skipped
X — Y would be premature/YAGNI here because Z." This matters as much as
justifying what you did include.

## 6. Output

- If Claude Code's plan-mode is active, write this phased plan into the plan
  file as usual (Context → phases → critical files → verification).
- Otherwise, present it directly as a phased plan in the response, using the
  structure from §2 for each phase, followed by a short "Suggested build
  order" list and an overall verification section.
