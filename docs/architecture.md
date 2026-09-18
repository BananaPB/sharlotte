# Technical architecture

> Maintained by the Documentation agent (`/doc`) after each structuring feature merge.

## Overview

- Backend: Laravel 13 (PHP 8.3+)
- Frontend: React 19 + Inertia.js + TypeScript
- UI: Tailwind CSS + shadcn/ui
- Tests: Pest PHP (backend), Vitest (frontend)

## Data access: one gatekeeper, three surfaces _(planned)_

The database is never talked to directly — not by the web UI, not by the public API, not by a future consumer. Everything goes through the Laravel app: Eloquent models, Policies for authorization, FormRequests for validation (see [`CLAUDE.md`](../CLAUDE.md) section 3). The app then exposes the same underlying data through three separate surfaces:

```
                      ┌─────────────────────────┐
                      │   sharlotte (Laravel)    │
                      │                           │
Web UI (Inertia) ───▶│  Eloquent models          │───▶  MySQL/Postgres
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

_(to be completed by the Documentation agent as features are built)_
