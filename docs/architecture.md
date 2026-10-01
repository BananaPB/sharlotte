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

The `Ingredient` model is the leaf of the `Product > Preparation > Ingredient` tree described in [`domain-model.md`](domain-model.md): a raw, bought-as-is item carrying its own nutrition (per 100g) and allergens directly, with no recipe of its own. `Product` and `Preparation` (the recursive composition and calculation engine on top of this) are Phase 2 ([`decisions.md`](decisions.md) entries 8 and 10): formats & units and the preparation/product/recipe-line tables are built (below); the cycle guard and the calculation engine are not yet. This phase ships no controllers, Policies, FormRequests, or Inertia pages — it is deliberately data-layer only ([`roadmap.md`](roadmap.md) defers the public API to Phase 3).

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
- `owner_id` is **not** mass-assignable (absent from `$fillable`, same on `Unit`). Ownership is always set explicitly from the authenticated user (`owner()->associate($user)`) or, for public data, by assigning `owner_id = null` directly — request input can never choose the owner, and through it the privacy.

**Import**

`App\Console\Commands\ImportIngredientsCommand` (`ingredients:import {path}`) upserts the public ingredient dataset (~1,937 rows) from a UTF-8 CSV export of the source spreadsheet, keyed on the computed slug — safe to re-run as the spreadsheet is corrected. It reads the file with PHP's built-in `fgetcsv()` (no `.xlsx`-parsing dependency; the developer exports to CSV himself) and validates rather than assumes clean input: unrecognized category/allergen/storage codes, malformed or out-of-range numeric values, duplicate slugs, and French-locale CSV quirks (comma-decimals, semicolon-delimiters, a BOM) are all reported per-row without aborting the rest of the run.

Rules worth knowing when touching it:

- Header names are matched case-insensitively (the real export uses lowercase headers) and rewritten to their canonical casing, so the rest of the command only ever sees one spelling.
- A cell containing the literal text `null` is treated as blank. Error messages still quote the raw cell, so the reported value matches what's actually in the file.
- A row missing any of the seven core nutrition values is rejected; blank `Fibers`/`Water` import as `null`. Calories are stored as parsed, never rounded ([`decisions.md`](decisions.md) entry 9).
- The upsert is spelled out as `firstOrNew(['slug' => …])` + `fill()` + explicit `owner_id = null` + `save()` rather than `updateOrCreate()`, because `owner_id` isn't fillable. Every imported row, created or updated, is (and stays) public.
- The upsert never writes `to_review`, so re-importing a corrected spreadsheet keeps manual review flags.

### Formats & units (Phase 2 step 1 — data layer only)

A unit lets a quantity be entered as "2 tranches" instead of grams: it gives one **format** a weight in grams for one specific component — an ingredient or a preparation (e.g. "Jambon: 1 tranche = 40 g", "Pâte sablée maison: 1 pièce = 300 g"). Recipes still reason in grams only; see [`domain-model.md`](domain-model.md) §Units and [`decisions.md`](decisions.md) entry 8. Like Phase 1, no controllers, Policies, FormRequests or pages yet.

**Schema**

- `formats` — closed, read-only lookup list: `code` (unique, stable English identity), `label_fr` and `label_fr_plural`. The plural is an explicit column so display picks singular/plural by quantity and never computes French plurals in code. `FormatSeeder` (called from `DatabaseSeeder`) seeds the 6 rows idempotently via `updateOrCreate` on `code`: `slice`, `bottle`, `box`, `pack`, `piece`, `clove`.
- `units` — component is `ingredient_id` **or** `preparation_id` (both nullable, both `cascadeOnDelete`: a unit means nothing without its component), exactly one set, enforced by `CHECK (num_nonnulls(ingredient_id, preparation_id) = 1)` (`units_one_component`). Plus `format_id` (`restrictOnDelete`: a format in use can't be removed) and `owner_id` (nullable, `cascadeOnDelete`, like private ingredients). All four FKs explicitly indexed. `grams` is `decimal(8,2)` with a DB `CHECK (grams > 0)` (`units_grams_positive`), cast to `decimal:2`. No uniqueness on (component, format, owner): near-duplicates like "tranche fine" / "tranche épaisse" are accepted (entry 8).
- `Unit::component()` returns whichever of `ingredient`/`preparation` is set; it is not a relation, so eager-load both before calling it in a loop.
- `owner_id` null = public unit, supported by the schema but none seeded yet (see [`decisions.md`](decisions.md) Deferred, "Seed common public units"). Privacy is derived from `owner_id` (`Unit::isPublic()`), not stored.

**Ownership rules**

- A `saving()` hook on `Unit` enforces that a unit on a **private** component belongs to that component's owner, and throws a `LogicException` otherwise, since reaching it means a caller skipped validation/authorization. A private unit on a **public** ingredient is allowed. Preparations are always private, so a preparation unit always belongs to the preparation's owner. The component's owner is re-queried on each save (one query) rather than read from the cached relation.
- `Unit::visibleTo($user)` scope = public units + the user's own. `Ingredient::units()` returns all units unfiltered, so apply the scope when showing them to a user.
- Known limits: the hook doesn't run on bulk/query-builder writes, and it isn't re-checked when a component's owner changes.

### Preparations, products & recipe lines (Phase 2 step 2 — data layer only)

The composition layer of the `Product > Preparation > Ingredient` tree ([`domain-model.md`](domain-model.md), [`decisions.md`](decisions.md) entry 8). Tables and model rules only: no controllers, Policies, FormRequests or pages, and no calculation yet (step 4).

**Schema**

- `preparations` and `products` — same shape today: `name`, `owner_id` (`NOT NULL`, `cascadeOnDelete`, indexed), timestamps. Always owned, no public tier; `owner_id` is not fillable (set via `owner()->associate($user)`). No yield column: a recipe weighs the sum of its lines in grams, computed rather than stored ([`decisions.md`](decisions.md) entry 10 and its Deferred line on cooking loss). Kept as two tables so products can gain their own fields later (entry 8).
- `recipe_lines` — one table for both kinds of recipe:
    - parent: `parent_preparation_id` or `product_id` (`cascadeOnDelete`: deleting a recipe deletes its lines);
    - component: `ingredient_id` or `component_preparation_id`. There is **no product component column**, so a product can never be used inside a recipe — enforced by the schema itself;
    - `unit_id` (nullable): `null` means `quantity` is in grams; otherwise `quantity` is a count of that unit (2 × "1 tranche = 40 g");
    - `position` (unsigned int, not unique, to keep reordering simple; lines are ordered by `position`, then `id`) and `quantity` (`decimal(10,2)`, cast to `decimal:2`);
    - every FK column explicitly indexed.
- Named CHECKs on `recipe_lines`: `recipe_lines_one_parent` and `recipe_lines_one_component` (`num_nonnulls(...) = 1`), `recipe_lines_quantity_positive` (`quantity > 0`), `recipe_lines_no_self_reference` (`parent_preparation_id <> component_preparation_id`). Only the **direct** self-reference is blocked; the full, transitive cycle guard is step 3.

**Model rules**

- Lines are created through their parent — `$product->recipeLines()->create([...])` — because the parent columns are not fillable (like `owner_id`, they decide whose data the line is). Component, unit, position and quantity are fillable.
- A `saving()` hook on `RecipeLine` throws a `LogicException` when:
    - the ingredient is private and not owned by the recipe's owner;
    - the component preparation isn't owned by the recipe's owner;
    - the unit doesn't weigh the line's own component, or is private and not owned by the recipe's owner.

    Shape rules (one parent, one component, quantity, self-reference) are left to the CHECKs. Cost: 2 queries per save, 3 with a unit, all re-queried fresh rather than read from cached relations. Same limits as `Unit`: not run on bulk/query-builder writes, not re-checked if an owner changes later.
- `App\Contracts\HasRecipe` (one method, `recipeLines()`), implemented by `Preparation` and `Product`, so the step 4 engine can be written once against recipe lines rather than per parent type.
- `RecipeLine::parent()` and `component()` return whichever side is set; they are not relations, so eager-load `parentPreparation`/`product` and `ingredient`/`componentPreparation` before calling them in a loop.
- `Preparation::usedInLines()` lists the lines that use a preparation as a component.

**In-use protection and account deletion** ([`decisions.md`](decisions.md) entry 11)

- The component and unit FKs on `recipe_lines` (`ingredient_id`, `component_preparation_id`, `unit_id`) are `NO ACTION`: deleting an ingredient, preparation or unit that some line uses fails at the database level.
- Account deletion: `User` has a `deleting` hook that first deletes, in one query, every recipe line whose parent belongs to that user; the DB cascade then removes their preparations, products, units and private ingredients. `User::delete()` wraps the whole thing in a transaction.
- Consequence: users must be deleted **one at a time through the model** (`$user->delete()`). A bulk `User::query()->delete()` skips the hook and fails on the foreign keys.

### Data storage

Postgres 17 everywhere — development, CI, and the Pest suite — per [`decisions.md`](decisions.md) entry 5. See "Local environment" above for how the dev/test databases and CI are kept in sync.

_(to be completed by the Documentation agent as further features are built)_
