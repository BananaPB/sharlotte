# Roadmap

## Two-part context

This repo (`sharlotte`, public, open source license) is the first of two projects in the broader vision:

1. **`sharlotte`** (this repo) — the app for individuals: recipes, nutrition, allergens, Cooklang export, blog/PDF generation.
2. **A B2B SaaS** (separate, private repo, later development) — adds price/cost/margin, labels, a legal allergen register, multi-user by team ("teams"). Will consume this repo's public API for ingredients and nutrition/allergen calculation instead of duplicating this engine.

The B2B SaaS is not in scope for this repo. Do not anticipate its needs (pricing, teams, etc.) in `sharlotte`'s code — see [`CLAUDE.md`](../CLAUDE.md) and [`vision.md`](vision.md).

## Phases

### Phase 1 — Ingredient database

- Migrate the ~2,000 Excel entries to a real database.
- `Ingredient` model (nutrition + allergens).
- No public API yet — see Phase 3. Freezing an API contract before the domain engine (Phase 2) has validated the data model would mean building it against a shape likely to change.

### Phase 2 — Domain engine

Schema choices are recorded in [`decisions.md`](decisions.md) entries 8 and 9. Data layer and engine only — no controllers or pages (Phase 3). One `/feature` per step, in order:

0. **Require core nutrition** — `calories`, `fats`, `saturates`, `carbohydrates`, `sugars`, `proteins`, `salt` become `NOT NULL` (`fibers`, `water` stay nullable); `calories` becomes `decimal(6,2)` and the import stops rounding it; the import rejects rows missing a core value; then re-run `ingredients:import` to restore the 734 decimal calorie values lost to rounding.
1. **Formats & units** — seeded, closed `formats` list with explicit singular and plural labels (tranche, bouteille, boîte, paquet, pièce, gousse); `units` table (component, decimal grams, format, nullable `owner_id` = public). Public units are supported but not seeded yet ([`decisions.md`](decisions.md), deferred 2026-10-01).
2. **Preparations, products & recipe lines** — `preparations` (with nullable `yield_grams`, defaulting to the sum of lines) and `products` tables; shared `recipe_lines` table (`position`, decimal `quantity`, nullable `unit_id` = grams) with explicit parent/component foreign keys and CHECK constraints; a line's unit must belong to its component.
3. **Cycle guard** — refuse to save a recipe line that would make a preparation contain itself, directly or transitively.
4. **Calculation engine** — unroll to grams, flatten the recipe, aggregate nutrition (scaled by yield), union allergens and traces. Exhaustive Pest suite as top priority — it's the most critical piece of the project. `fibers`/`water` are reported unknown for the whole tree if any leaf lacks them ([`decisions.md`](decisions.md) entry 9).

### Phase 3 — "Individual" app

- Public JSON API: read access to ingredients/preparations/products, rate-limited; user-scoped creation for private entries (moved here from Phase 1 — see the note above).
- Recipe/product CRUD on top of the domain engine.
- Export to Cooklang format.
- Static blog generation, recipe book, printable sheets.

### Phase 4 (out of this repo) — B2B SaaS

- New, private repo.
- Consumes this repo's API for ingredients + nutrition/allergen calculation.
- Adds: cost/margin, labels, legal allergen register, multi-tenant (teams).

## Current state

Phase 1 (ingredient database) is complete: lookups, `Ingredient` model and the `ingredients:import` command are merged, and the importer has been run against the real ~1,937-row dataset. Phase 2 (domain engine) is underway. Step 0 (require core nutrition) is done; step 1 (formats & units) is next.

_(to be kept up to date by the Documentation agent as work progresses — which phase is underway, what's done)_
