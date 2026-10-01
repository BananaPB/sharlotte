# Decision log

> Maintained by the **CTO** (`/cto`). Not by the Documentation agent.

Why the project is built the way it is: what was chosen, what was rejected, and what it costs.

This is **not** the same document as [`architecture.md`](architecture.md). That one describes what the architecture _is_ today and gets rewritten as things change — that is its job. This one is append-only history and is never rewritten, because the rejected options and the reasons behind them are exactly what gets silently deleted otherwise.

**Rules**: newest at the top. A decision that changes gets a **new** entry saying it supersedes the old one; the old entry stays put. Record something here when it is expensive to reverse, when it constrains future work, or when you would reasonably ask "why on earth is it done this way?" in six months. Naming a variable is not a decision. Choosing a database is.

---

## Open — not decided yet

Blocking or imminent, no entry below yet. This is the agenda for the next `/cto` session.

- **Where does this get hosted, and for how much?** Decisions 2 and 4 below both assume a cost-constrained host. Nothing records what it actually is.

## Deferred

Real decisions, deliberately not made yet, each with the event that should bring it back.

- **Extract the calculation engine into a shared Composer package** — when a second real consumer exists _and_ the HTTP coupling in decision 3 is measurably painful. _(deferred 2026-09-21)_
- **Cache ingredient data on the B2B side to survive sharlotte outages** — when Phase 4 starts. _(deferred 2026-09-21)_
- **Translate ingredient/category data and add multi-locale support** — when an actual non-French-speaking user or use case appears (e.g. publishing the CC-BY dataset in English). _(deferred 2026-09-22)_
- **Seed common public units (e.g. "1 tranche de jambon = 40 g")** — public units (`owner_id` null) are supported by the schema per entry 8, but none are seeded yet. Seed them when Phase 3's unit picker exists and users would otherwise each recreate the same common units. _(deferred 2026-10-01)_
- **"Family"/team-shared ingredient visibility (brand accounts sharing ingredients across shops)** — not unless a B2B SaaS customer (Phase 4, separate repo) needs it there; this repo stays one-account-one-person per `vision.md`. _(deferred 2026-09-22)_

---

_Entries 1–4 were recorded retroactively on 2026-09-21 from [`vision.md`](vision.md), [`roadmap.md`](roadmap.md), and [`architecture.md`](architecture.md). They describe choices already made at repo initialization, not new ones._

## 9. Core nutrition values are mandatory; calories stored as exact decimals

**2026-10-01 · Accepted · applies to Phase 1 data, ahead of the Phase 2 engine**

Phase 1 made every nutrition column nullable on purpose, `null` meaning "unknown" (see `architecture.md`), which left the calculation engine an open question: what is a tart's calorie total when one ingredient's calories are unknown? Rather than answer it in the engine, the project owner ruled unknown values out at the source for the basics. A check of the real dataset (`sharlotte-database-v5.csv`, 1,937 rows) found zero empty nutrition cells in any column, so the rule costs nothing on public data. So: the EU's mandatory seven — `calories`, `fats`, `saturates`, `carbohydrates`, `sugars`, `proteins`, `salt` — become `NOT NULL`, and the import rejects a row missing any of them. `fibers` and `water` stay nullable; the engine reports either as unknown for a whole tree if any leaf lacks it, never as a partial sum. The same check found 734 rows with decimal calories (e.g. 72.8) that the import rounded to an integer before storing — rounding before summing, the inexact arithmetic decision 5 chose Postgres to avoid. So `calories` also becomes `decimal(6,2)`, the import stops rounding, and the dataset is re-imported to restore the lost decimals (converting the column alone keeps the rounded values).

**Rejected**: keeping all columns nullable and having the engine propagate or flag unknowns (pushes a data-quality problem into the most critical code, and every result gains an "incomplete" state the UI must explain); making all nine columns mandatory (forces a user entering a private ingredient to invent a water or fiber value — a fake `0` is worse than an honest unknown); keeping integer calories (error is at most 0.5 kcal/100 g per ingredient, small, but it compounds across a recipe and is free to fix while these columns are being migrated anyway — rounding belongs in display only).

**Costs**: users creating private ingredients must know all seven core values, which some won't have to hand — a real friction point for the Phase 3 creation form, with no "I don't know" escape. Changing existing columns to `NOT NULL` is a change to live columns (CLAUDE.md §3.8); safe only because zero nulls exist today, and `down()` must restore nullability. The engine still carries `null` handling for two fields rather than none. Re-import is needed on every database already seeded with rounded values — trivial while only dev databases exist, a step to remember if this lands after production data.

**Revisit when**: user feedback on the Phase 3 form shows people abandoning private ingredients because a core value is unknown.

## 8. Three separate entities, one shared recipe-line table, explicit foreign keys

**2026-10-01 · Accepted · applies to Phase 2**

Phase 2 needs a schema for `Preparation` and `Product` on top of the existing `Ingredient`. Preparations and products both have no direct nutrition (it is always computed from their composition) and both are recursive, so merging them — or folding preparations into `Ingredient` as "an ingredient with a recipe" — were both on the table. The recursive engine, though, operates on a list of recipe lines, not on the parent entity: as long as both expose the same `recipeLines()` relation, it is written once regardless of table count. So: `ingredients`, `preparations`, and `products` stay three tables, sharing a single `recipe_lines` table (`position`, decimal `quantity`, nullable `unit_id` meaning grams). Its parent is two nullable foreign keys (`preparation_id` / `product_id`) and its component two more (`ingredient_id` / `preparation_id`), each pair under a Postgres CHECK that exactly one is set — there is deliberately no `product_id` component column, so "a product is never a component" is enforced by the schema, not a validation rule. Preparations carry a nullable `yield_grams` (cooked weight, e.g. evaporation loss), defaulting to the sum of their lines. A closed, seeded list of `formats` (tranche, paquet, bouteille, pot…) and a `units` table (component, decimal grams, format, nullable `owner_id`) implement the unit conversion described in `domain-model.md`. Common units ("1 tranche de jambon = 40 g") are seeded once as public rows (`owner_id` null) on public ingredients, rather than copied into each new account as the previous Mongo version of this app did; users add their own private units on top.

**Rejected**: one shared `recipes` table with a `type` discriminator for preparations and products (briefly recommended in the same session, then withdrawn — its only real argument, avoiding a duplicated engine, does not hold, and it turns a schema guarantee into a global scope that one bug can bypass); folding `Preparation` into `Ingredient` (loses the structural guarantee that a leaf has no composition, and adds a second filter on decision 4's CC-BY export, the place private rows are already most at risk of leaking); Laravel polymorphic relations (`morphTo`) for parent/component (no real foreign keys, so no `restrictOnDelete` — deleting a pastry cream would silently orphan every tart line using it, exactly the failure the Mongo version had); integer unit quantities (a pinch of salt is ~0.5 g); per-user copies of basic units (N users × M identical rows, and a correction to one never reaches the others).

**Costs**: four nullable foreign keys plus two CHECK constraints on `recipe_lines` is more verbose than a `morphTo`, and the CHECKs live in raw migration SQL (static, no user input — CLAUDE.md §3.2), which Eloquent will not validate for you; each needs a test proving the database rejects a both-null and a both-set row. Walking a recursive tree through relations is N+1-prone by construction — the engine needs a deliberate loading strategy, not per-node lazy loads (CLAUDE.md §3.1). Public units cannot be edited by a user who disagrees with them (his slices are 30 g); he creates a private one instead, so a component can end up with two near-identical units. Units and lines must also be validated to belong together (no "1 tranche" unit on a milk line) — an application rule, not a schema one. Product-only fields will accumulate on `products` over time; selling price, cost and margin are **not** among them (`vision.md` — B2B SaaS scope).

**Revisit when**: a third kind of parent or component appears (the four-FK pattern stops scaling past this), or public units turn out to be edited-around so often that per-user overrides of a public unit become worth modeling.

## 7. Autonomous dev→audit→test→PR pipeline, human gates only at task-selection and merge

**2026-09-24 · Accepted**

Up to now, `/feature`, `/audit`, and `/test` each stopped and waited for a manual trigger for the next step, including committing/pushing/opening the PR and checking CI/review results, even though the only two decisions that actually mattered — what to build, and whether a PR is good enough to merge — were untouched by that friction. So: the orchestrating session now chains dev → audit (capped auto-fix-loop) → test (capped auto-fix-loop) → commit → push → PR automatically once told to start, then watches CI and the automated PR review (via the `loop` skill) until the PR is clean, dispatching fix agents as needed, and only surfaces back to the user when it's ready for review — or immediately if a failure repeats past 2 rounds on the same issue, or 4 total rounds on one step (so a fix that introduces a _different_ new problem each round doesn't dodge the cap by never repeating the old one), or looks like something only the user can fix (local machine/environment issues, confirmed a real failure mode firsthand via the Postgres/WSL2/PHP-driver debugging chain in this same session). CRITICAL security findings from `/audit` always stop the chain for an explicit human call, regardless of the rest of this entry. Once told a PR has merged, the session also runs the post-merge cleanup (`git checkout main && git pull && git branch -d <branch>`) itself, without needing `/cto`'s wrap-up mode — that step is pure local git with no push/PR/merge risk, unlike the rest of this chain.

**Rejected**: keep every step manual (the status quo) — matches a real, stated frustration with constant re-prompting, with no corresponding safety benefit since nothing merges without review either way; fully autonomous through merge — explicitly rejected, since a bad autonomous merge is much costlier to unwind than a bad autonomous PR sitting unreviewed.

**Costs**: every future `/feature` run pushes branches and opens PRs without a mid-flight confirmation — a real trust escalation from "confirm before every push" to "confirm before every merge." A capped fix-loop can still spend a round or two chasing a failure that isn't actually fixable by an agent before the escalation rule catches it. The local `npm run check:fix` step can't run autonomously from this session's environment (Git Bash has a confirmed dead end here, see `docs/git-github-workflow.md` §9.4) — the pipeline relies on CI's formatting gate instead, costing an occasional extra CI round-trip when formatting drifts, rather than blocking the automation on a step that can't run headlessly here.

**Revisit when**: the fix-loop burns meaningful time or tokens on something it shouldn't have retried, or a future environment change (e.g. moving the toolchain into WSL2, floated earlier this session) makes the local formatting check runnable autonomously after all.

## 6. npm, not pnpm

**2026-09-21 · Accepted**

A prior commit pinned pnpm via `devEngines.packageManager` in `package.json` without updating `tests.yml` or `composer.json`'s `setup` script to match — both still call plain `npm`. The mismatch broke `ci` (`EBADDEVENGINES`) across three sessions running before it was traced to this. So: stay on npm, remove the pnpm pin.

**Rejected**: keep pnpm and fix CI/composer.json to match it instead — pnpm's real advantages (a shared content-addressable store, strict dependency isolation, fast installs in a large monorepo) don't apply here. This is one `package.json`, not a workspace (`pnpm-workspace.yaml` lists exactly one package, `.`), and `npm install` finishes in ~9 seconds on this dependency set. `pnpm-workspace.yaml` already carries a `publicHoistPattern` escape hatch for `@inertiajs/core` — pnpm's strict isolation already broke something here once, before a single feature was written. The pnpm pin was never a deliberate choice to begin with; it was incidental to a formatter running once through pnpm.

**Costs**: none identified — npm ships with Node, needs no CI setup step, and nothing here needs pnpm's workspace features. The only real cost was already paid: three sessions of CI confusion tracing a mismatch nobody had decided on.

**Revisit when**: this repo becomes an actual multi-package workspace. Not expected to — the B2B SaaS is a separate repo by design (decision 3), so there's no monorepo in this project's future to plan around.

## 5. Postgres everywhere — development, production, and tests

**2026-09-21 · Accepted**

The starter kit defaults to SQLite (`DB_CONNECTION=sqlite` in `.env.example`, `DB_DATABASE=:memory:` in `phpunit.xml`), and no domain migrations exist yet, so the choice was still free. The crown-jewel engine (Phase 2) does recursive decimal arithmetic over a Product > Preparation > Ingredient tree, and search over the ~2,000-row ingredient dataset wants typo tolerance. So: Postgres in development, production, **and** the test suite — moving tests off SQLite in-memory onto a real Postgres database.

**Rejected**: MySQL (the only real argument is developer familiarity, which Eloquent mostly hides for CRUD anyway; the one scenario where it clearly wins — cheap shared hosting with cron-driven jobs — is already ruled out by decision 4, whose scheduled export command needs reliable cron, not shared-host cron); staying on SQLite (type affinity lets a string into a decimal column and does float math where the domain needs exact decimals — wrong foundation for a nutrition calculator, and the default the starter kit ships with almost by accident).

**Costs**: local Postgres now required for development (Docker or Herd) instead of zero-setup SQLite; a `postgres:` service container needed in `tests.yml`; and — the one that actually stings — **the test suite permanently loses SQLite in-memory speed**, realistically 2–5× slower once the Phase 2 suite is large. Paid deliberately: that suite validates nutrition arithmetic, and it should validate it against the numeric semantics the app actually ships on. Cheap shared hosting (OVH/cPanel-style) is also ruled out as a hosting option from here on.

**Revisit when**: not expected before Phase 3 ships.

## 4. Distribute the public dataset as a periodic static dump, not live API pagination

**2026-09-21 · Accepted · applies to Phase 1**

The CC-BY ingredient dataset (~2,000 rows, changing rarely) must be downloadable in bulk per decision 2. The obvious path — letting people page through `/api/v1/ingredients` — makes every bulk consumer hammer the application database on a budget host. So: a scheduled artisan command dumps the public `Ingredient` rows to a static CSV/JSON file served from storage or a CDN, refreshed periodically. Bulk consumers take the file; the live API stays for per-entity queries.

**Rejected**: live paginated API for bulk reads (every full crawl is 2,000 rows of DB work for data that barely changes, competing with the app's own traffic); on-demand export per request (same load, plus a slow endpoint and a timeout risk); no bulk download at all (breaks the CC-BY promise in practice — a dataset you can only scrape is not really redistributable).

**Costs**: the dump is **stale by design**, so the refresh interval needs stating publicly and the file needs a generation timestamp, or consumers will assume it is live. The export command is also the single place where private user entries could leak into public data (decision 2) — it needs a dedicated test asserting private rows never appear, written alongside the command rather than later. And it is one more scheduled job to monitor: a silently failing cron serves an increasingly stale file with nothing surfacing the error.

**Revisit when**: the dataset outgrows a single file, or consumers need near-real-time freshness. Neither is plausible at 2,000 rows.

## 3. Keep the B2B SaaS a separate repo consuming sharlotte's public HTTP API

**2026-09-21 · Accepted · applies to Phase 4**

Two products need the same recursive nutrition/allergen engine: this open-source app, and a private B2B SaaS adding cost/margin, labels, and multi-tenancy. Same author, different licenses, different audiences, different release cadences. So: two repos, two databases, and the B2B app consumes sharlotte's public API as an ordinary HTTP client with its own privileged token rather than the public rate limit.

**Rejected**: a monorepo (forces one license boundary and one deploy cadence onto two products with opposite openness requirements — private B2B code would sit in a public repo's history); extracting the engine into a shared Composer package (needs a stable package API _before_ a second consumer exists to validate it, plus separate ingredient-data syncing — premature, now in Deferred above); copying the engine into the B2B repo (two divergent implementations of the most correctness-critical code in the project).

**Costs**: **the B2B SaaS depends on sharlotte's uptime for every calculation** — a hobby-tier outage on the open-source app becomes an outage for paying customers. That is the real price of this decision and it is not yet mitigated. The API contract also becomes load-bearing the moment the B2B app exists, needing `/api/v1/` versioning discipline a single-consumer app would not need. And it is two repos, two deploys, two CI pipelines for one developer.

**Revisit when**: Phase 4 starts, or earlier if the uptime coupling becomes concrete enough to hurt. The fallback is the shared-package option.

## 2. License the code MIT and the ingredient dataset CC-BY 4.0

**2026-09-21 · Accepted**

The repo holds two assets of different natures: application code, and a hand-built database of ~2,000 ingredients representing ten years of restaurant work. A single repo license would have silently applied code terms to the data. So: code MIT, dataset CC-BY 4.0, published as a standalone download as well as through the hosted app, rate-limited rather than account-gated.

**Rejected**: one license for the whole repo (software licenses do not cleanly govern datasets — MIT on data leaves attribution unenforceable); keeping the dataset proprietary (contradicts the open-source framing, and the dataset is the main reason anyone would adopt this); gating downloads behind accounts (a signup wall and user management to protect data being given away anyway — rate limiting solves the actual problem, hosting cost, rather than a made-up one).

**Costs**: the public dataset must stay **physically separable** from user-created private entries at every layer — a permanent correctness constraint on Phase 1's schema and on the export command, and it needs a test. The B2B SaaS reuses this data under CC-BY, so its outputs may carry attribution obligations worth confirming before it has customers. MIT also means a competitor can fork the engine outright — accepted deliberately, since the moat is the data and the domain knowledge, not the code.

**Revisit when**: not expected for the code. Re-examine the data license if the B2B product's attribution obligations turn out to be commercially awkward — before Phase 4 ships, not after.

## 1. Ship as a single Laravel + Inertia + React application

**2026-09-21 · Accepted**

Solo hobbyist developer from a Symfony + React background, building evenings and weekends with no ops support. The app needs an authenticated web UI (Phase 3) and a public read API (Phase 1), and the goal is to reach a working domain engine (Phase 2) rather than spend months on plumbing. So: one Laravel 13 app serving React 19 through Inertia.js on the official starter kit, with the public JSON API as a second surface on the _same_ application rather than a separate service.

**Rejected**: Laravel API + standalone React SPA (two deployables, a token/session auth layer to build and secure by hand, routing duplicated on both sides — Inertia removes all three for a single-client app); Symfony + React, the familiar stack (familiarity is real, but Laravel's starter kit ships auth, Inertia wiring and the React/TS/Tailwind toolchain pre-assembled, against a framework whose concepts map closely onto Symfony's); Next.js (would put the domain engine in TypeScript, when PHP is the stronger language here and that engine most needs the language he is most careful in); Blade/Livewire only (discards existing React skill, and the nested recipe-composition UI is exactly what a client-side framework handles better).

**Costs**: the frontend is coupled to the Laravel deploy, so no independent frontend release. Inertia gives no SEO or SSR by default — **this matters**, because Phase 3's static blog generation is not served by Inertia and will need its own build path, a separate piece of work rather than a free side effect. And any future non-web consumer must go through the public API, which is therefore load-bearing from Phase 1, not a Phase 4 concern.

**Revisit when**: not expected. Reversing this after Phase 3 would mean rewriting every page's data layer.
