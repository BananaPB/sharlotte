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
- API: public read access, user-scoped creation for private entries.

### Phase 2 — Domain engine
- `Product` / `Preparation` models (recursive composition of components, see [`domain-model.md`](domain-model.md)).
- Recursive aggregated calculation: unrolled recipe, nutrition, allergens.
- Exhaustive Pest test suite on this calculation as top priority — it's the most critical piece of the project.

### Phase 3 — "Individual" app
- Recipe/product CRUD on top of the domain engine.
- Export to Cooklang format.
- Static blog generation, recipe book, printable sheets.

### Phase 4 (out of this repo) — B2B SaaS
- New, private repo.
- Consumes this repo's API for ingredients + nutrition/allergen calculation.
- Adds: cost/margin, labels, legal allergen register, multi-tenant (teams).

## Current state

_(to be kept up to date by the Documentation agent as work progresses — which phase is underway, what's done)_
