# Sharlotte

Sharlotte is an app for writing, archiving and organizing recipes centered on **finished products**. You compose a product (a strawberry tart) from raw ingredients and from preparations (a pastry cream), and the app derives the full recipe, the aggregated nutrition facts and the allergen list, with no re-entry.

It comes from a restaurant professional's own database of about 2,000 raw ingredients with nutrition and allergen data, and from the wish to stop calculating all of this by hand. The full pitch is in [`docs/vision.md`](docs/vision.md).

## Status

Early development: the ingredient database and recipe structure are in place, and the calculation engine is half built (recipes flatten to grams; nutrition and allergens are next). See the "Current state" section of [`docs/roadmap.md`](docs/roadmap.md) for where things stand.

## Run it locally

Requirements: Docker, plus either the dev container or PHP 8.3+, Composer and Node.js on your machine.

**Dev container (VS Code):** open the repository in the dev container. On first start it runs `.devcontainer/post-create.sh`, which installs dependencies, creates `.env`, builds the frontend and migrates the database.

**Without the dev container:**

```bash
docker compose up -d   # Postgres 17 (dev and test databases)
cp .env.example .env   # then set DB_PASSWORD=postgres in .env (the Docker database's password)
composer setup         # install, generate key, migrate, build assets
composer dev           # start the development servers
```

Run the tests and checks with `composer test` (lint, static analysis, Pest) and `npm run check` (formatting and lint of JS/TS and markdown).

## Licences

- **Code**: MIT, see [`LICENSE`](LICENSE).
- **Ingredient dataset**: CC-BY 4.0. Free to use and redistribute, including commercially, with attribution. The split and its reasons are in [`docs/decisions.md`](docs/decisions.md) (entry 2).

## Documentation

- [`docs/architecture.md`](docs/architecture.md): how it is built today
- [`docs/domain-model.md`](docs/domain-model.md): the Product, Preparation and Ingredient vocabulary
- [`docs/roadmap.md`](docs/roadmap.md): phases and current state
- [`docs/decisions.md`](docs/decisions.md): what was chosen, what was rejected
- [`docs/changelog.md`](docs/changelog.md): what changed, in plain language
