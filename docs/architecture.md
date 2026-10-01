# Technical architecture

> Maintained by the Documentation agent (`/doc`) after each structuring feature merge.

## Overview

- Backend: Laravel 13 (PHP 8.3+)
- Frontend: React 19 + Inertia.js + TypeScript
- UI: Tailwind CSS + shadcn/ui
- Database: Postgres 17, in development, CI, and the test suite alike — see [`decisions.md`](decisions.md) entry 5
- Tests: Pest PHP (backend), Vitest (frontend)

## Local environment

`docker-compose.yml` runs a single `postgres:17-alpine` service holding two databases: the dev database (`DB_DATABASE`, default `sharlotte`) and a `testing` database used only by the Pest suite, created on first boot by `docker/postgres/init-testing-db.sql` (Postgres only auto-creates the one database named by `POSTGRES_DB`). Credentials are read from the same `.env` file Laravel itself reads, via Compose's own `.env`-loading convention, so the container and Laravel's `DB_*` config can't drift apart. `.github/workflows/tests.yml` runs an equivalent `postgres:` service container so CI exercises the same engine as local dev.

## Data access: one gatekeeper, three surfaces _(planned)_

The database is never talked to directly — not by the web UI, not by the public API, not by a future consumer. Everything goes through the Laravel app: Eloquent models, Policies for authorization, FormRequests for validation (see [`CLAUDE.md`](../CLAUDE.md) section 3). The app then exposes the same underlying data through three separate surfaces:

```
                      ┌─────────────────────────┐
                      │   sharlotte (Laravel)    │
                      │                           │
Web UI (Inertia) ───▶│  Eloquent models          │───▶  Postgres
                      │  (Ingredient/Preparation/ │      (implementation
Public API ─────────▶│   Product + calc engine)  │       detail, never
(rate-limited)        │  Policies + FormRequests  │       exposed directly)
                      │                           │
Dataset export ──────▶│  (scheduled artisan       │
(CC-BY download)       │   command → static file)  │
                      └─────────────────────────┘
```

- **Web UI** — Inertia pages (Phase 3).
- **Public JSON API** — e.g. `/api/v1/ingredients`, `/api/v1/preparations`, `/api/v1/products/{id}`, built on Laravel API Resources. Read-only and unauthenticated for public entries; `Sanctum`-authenticated for a user's private ones. Throttled per IP (`throttle:` middleware) to keep hosting costs bounded — see [`vision.md`](vision.md)'s licensing section.
- **Dataset download** — not live API pagination. A scheduled artisan command dumps the public `Ingredient` table (CC-BY data only, never private user entries) to a static CSV/JSON file served from storage/CDN and refreshed periodically. Cheap to serve, doesn't compete with the app's DB connections.

The recursive Product → Preparation → Ingredient calculation (see [`domain-model.md`](domain-model.md)) lives entirely inside the Laravel app and is reachable through both the UI and the API — one implementation, two front doors.

## B2B SaaS boundary _(planned, Phase 4)_

Per [`roadmap.md`](roadmap.md), the B2B SaaS is a **separate, private repo** with its own database, consuming sharlotte's public API as an HTTP client — not a shared package, not a monorepo, not an embedded copy of the engine.

```
┌───────────────────────┐        HTTP (API token,           ┌─────────────┐
│   B2B SaaS (private)   │        higher/no rate limit)      │  sharlotte   │
│                         │ ──────────────────────────────▶ │  public API   │
│  teams, pricing,        │                                   │              │
│  cost/margin, labels,   │ ◀── ingredients, nutrition,      └─────────────┘
│  legal allergen sheets  │     allergens, aggregated calc
└───────────────────────┘
```

Since both repos share the same author, the B2B app gets its own privileged API token (or an internal-only endpoint tier) rather than sharing the public rate limit — same trust model as any first-party client. This keeps the two repos decoupled: sharlotte can change its internals freely as long as the API contract holds, per the "modular and self-contained" principle in [`vision.md`](vision.md).

Open questions to revisit when Phase 4 actually starts (not urgent before then):

1. **Runtime coupling** — the B2B SaaS depends on sharlotte's uptime for every calculation. Likely mitigation: cache ingredient/nutrition data on the B2B side (it changes rarely) and hit the live API mainly for the aggregation itself or on cache misses.
2. **API vs. shared package** — the alternative to "call over HTTP" is extracting the recursive calculation engine into a standalone, framework-agnostic PHP package both repos `composer require`, removing the network dependency at the cost of syncing ingredient data separately (probably via the CC-BY export). Starting with the API-consumer route is simpler and easy to revisit if the coupling becomes painful — no need to build a shared package before there's a second real consumer to justify it.

## Functional domains

### Ingredients (Phase 1 — data layer only)

The `Ingredient` model is the leaf of the `Product > Preparation > Ingredient` tree described in [`domain-model.md`](domain-model.md): a raw, bought-as-is item carrying its own nutrition (per 100g) and allergens directly, with no recipe of its own. `Product` and `Preparation` (the recursive composition and calculation engine on top of this) are Phase 2: their schema is decided ([`decisions.md`](decisions.md) entry 8) but not yet built. This phase ships no controllers, Policies, FormRequests, or Inertia pages — it is deliberately data-layer only ([`roadmap.md`](roadmap.md) defers the public API to Phase 3).

**Schema**

- `ingredient_categories` and `allergens` — small lookup tables (`code` is the stable identity to join/compare on in application code; `label_fr` and `description_fr` are display text, translatable later per the same deferred-i18n reasoning — see [`decisions.md`](decisions.md), deferred 2026-09-22). `IngredientCategorySeeder` seeds all 20 of the source spreadsheet's real-world categories, sourced from an old export of the project owner's previous version of this app. One exception to "display-only": the CSV import resolves categories and allergens by their (normalized) `label_fr`, because that is what the spreadsheet contains — so seeded labels must match the spreadsheet's spelling exactly, quirks included (e.g. `Appéritifs & biscuits`, `Sésame` rather than the EU's "Graines de sésame"). Correcting a label's spelling breaks matching unless the source data changes with it. The `description_fr` column is non-nullable with no default, so adding it required `migrate:fresh` on any already-seeded dev database — noted here since it'll matter again if a similar column is added after production data exists.
- `ingredients` — belongs to `IngredientCategory` (`category_id`, `restrictOnDelete`: a category in use can't be deleted out from under its ingredients) and optionally to `User` (`owner_id`, nullable, `cascadeOnDelete`: a private ingredient has no meaning once its owner is gone). Both foreign keys are explicitly indexed — Postgres, unlike MySQL/InnoDB, does not auto-index FK columns.
- Nutrition columns (per 100g) split in two groups, per [`decisions.md`](decisions.md) entry 9:
    - the seven core values — `calories` (`decimal(6,2)`), `fats`, `saturates`, `carbohydrates`, `sugars`, `proteins`, `salt` (`decimal(5,2)`) — are `NOT NULL`;
    - `fibers` and `water` (`decimal(5,2)`) stay nullable: `null` means "unknown data point," distinct from `0` (e.g. `water` measured at zero grams) — see [`domain-model.md`](domain-model.md).

    The model casts all nine to `decimal:2` (returned as strings, not floats) so nutrition arithmetic in Phase 2 stays exact, per [`decisions.md`](decisions.md) entry 5. Calories are stored unrounded; rounding belongs to display only.

- `to_review` — boolean, default `false`, indexed. A manual data-quality flag the project owner sets on ingredients to come back to (implausible values, macros that don't add up, naming inconsistencies). It is not touched by the import, carries no workflow, and has no timestamp of its own (`updated_at` suffices).
- Allergens are modeled as **two** separate `belongsToMany` pivots — `ingredient_allergen` (definitely contains) and `ingredient_allergen_trace` (may contain traces of), exposed as `Ingredient::allergens()` / `allergenTraces()` — rather than one pivot with a `type` column, for query simplicity (each relation is a plain join, no extra `WHERE` on a type discriminator).

**Identity: storage, privacy, slug**

- `App\Enums\IngredientStorage` (`fresh`/`frozen`/`ambient` — `ambient` meaning stored at room temperature, mapped from the spreadsheet's "sec") is part of an ingredient's identity, not a mutable attribute — a frozen and a fresh version of "the same" food have different nutrition and can't substitute for each other in a recipe.
- `App\Enums\IngredientPrivacy` (`public`/`private`) is derived automatically from `owner_id`'s nullability by a model `saving()` hook, and is never independently settable — this keeps the two columns from drifting apart while still giving `privacy` its own stored, queryable column (the source spreadsheet and the domain model both treat it as first-class). Deliberately two-valued only; see [`decisions.md`](decisions.md)'s Deferred list (2026-09-22) for the rejected "family"/team-shared tier.
- `Ingredient::slug` is unique and built from `name + storage + owner_id` (`Ingredient::buildSlug()`), because the same name can legitimately exist more than once — different storage state, or the same name owned by different users.

**Import**

`App\Console\Commands\ImportIngredientsCommand` (`ingredients:import {path}`) upserts the public ingredient dataset (~1,937 rows) from a UTF-8 CSV export of the source spreadsheet, keyed on the computed slug — safe to re-run as the spreadsheet is corrected. It reads the file with PHP's built-in `fgetcsv()` (no `.xlsx`-parsing dependency; the developer exports to CSV himself) and validates rather than assumes clean input: unrecognized category/allergen/storage codes, malformed or out-of-range numeric values, duplicate slugs, and French-locale CSV quirks (comma-decimals, semicolon-delimiters, a BOM) are all reported per-row without aborting the rest of the run.

Rules worth knowing when touching it:

- Header names are matched case-insensitively (the real export uses lowercase headers) and rewritten to their canonical casing, so the rest of the command only ever sees one spelling.
- A cell containing the literal text `null` is treated as blank. Error messages still quote the raw cell, so the reported value matches what's actually in the file.
- A row missing any of the seven core nutrition values is rejected; blank `Fibers`/`Water` import as `null`. Calories are stored as parsed, never rounded ([`decisions.md`](decisions.md) entry 9).
- The upsert never writes `to_review`, so re-importing a corrected spreadsheet keeps manual review flags.

### Data storage

Postgres 17 everywhere — development, CI, and the Pest suite — per [`decisions.md`](decisions.md) entry 5. See "Local environment" above for how the dev/test databases and CI are kept in sync.

_(to be completed by the Documentation agent as further features are built)_
