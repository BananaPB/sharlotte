# Project vision

## In one sentence

An app for writing, archiving, and organizing recipes centered on **finished products**, with automatic calculation of the nutrition facts and allergens from their composition.

## Where it comes from

The project owner has worked in the restaurant industry for 10 years and has built a database of ~2,000 raw ingredients with their nutritional information and allergens. He already runs a recipe blog (`baroudeur-culinaire`, Astro + Starlight + Cooklang). This project is an extension of that: automating what is, today, calculated by hand.

## The problem being solved

Composing a product (e.g. a strawberry tart) from components — some raw (an ingredient bought as-is), others themselves recipes (a preparation, e.g. a pastry cream) — and automatically deriving, with no re-entry:

- the full recipe of the product,
- its aggregated nutrition facts,
- its list of allergens.

See [`domain-model.md`](domain-model.md) for the `Product` > `Preparation` > `Ingredient` naming convention used throughout this project.

## Who this is for (this project)

The **open source / individuals** branch of the broader vision (see [`roadmap.md`](roadmap.md) for the second, B2B leg, which lives in a separate, private repo).

An individual who likes to cook, keeps their own recipes, and wants:
- a clean recipe sheet with automatically calculated nutrition/allergens,
- to export to Cooklang format,
- to generate a static blog of their recipes, a recipe book, a printable sheet.

## What this project is not (for now)

- No notion of selling price, cost, or margin — that belongs to the separate B2B SaaS.
- No collaborative multi-user ("teams") — one account = one person. Each user can create their own private entries (ingredients, recipes) in addition to the public database.
- No regulatory labeling, no legal allergen register — regulatory use = B2B SaaS.

## Guiding principle

**Modular and self-contained.** Each building block (ingredient database, calculation engine, Cooklang export, blog/PDF generation) must be able to evolve without shaking the others. See [`CLAUDE.md`](../CLAUDE.md) for the concrete rules that follow from this.

## License

Code and data are licensed separately, because they're not the same kind of thing:

- **Code** (this repo) — MIT. Free to use, fork, and build on, including commercially.
- **Ingredient data** (the ~2,000-entry raw ingredient database, once migrated from Excel in Phase 1 — see [`roadmap.md`](roadmap.md)) — CC-BY 4.0. Free to use, redistribute, and build on, including commercially, with attribution. It will be downloadable as a standalone dataset in addition to being queryable through the hosted app, and the hosted app's usage will be rate-limited to keep hosting costs sane rather than gated behind an account.

This split follows from the project's guiding principle above: the dataset is a distinct building block from the code, so it gets its own terms rather than inheriting the code's license by default.
