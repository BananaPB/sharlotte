# Domain Model

This document describes the core business domain of the application — the most critical part of the project, to be well understood before touching any code that depends on it.

## Naming convention

The three core entities follow a strict hierarchy, from top-level to leaf:

**`Product` > `Preparation` > `Ingredient`**

- **`Product`** — the finished item (top of the tree, never reused as a component of something else).
- **`Preparation`** — an intermediate composition that has its own recipe (a node in the tree: it is itself composed of ingredients and/or other preparations, and can in turn be used as a component elsewhere).
- **`Ingredient`** — a raw, bought-as-is item with no recipe of its own (a leaf of the tree).

When we need to refer generically to "a thing that appears as a line in a recipe" — regardless of whether it's an `Ingredient` or a `Preparation` — we use the word **component**. This avoids the ambiguity of using "ingredient" both as the generic term and as the specific leaf-level entity name.

## The three concepts

### Ingredient

A raw product, bought as-is, **with no recipe**. It directly carries:

- its nutritional information (values per 100g, for example),
- its declared allergens.

Example: flour, butter, a frozen tart base.

Source: the existing database of ~2,000 entries (migrated from Excel), public, plus the private entries created by each user.

### Preparation

An intermediate preparation, **which has its own recipe**: it is composed of components (ingredients and/or other preparations).

Example: a pastry cream is a preparation, composed of milk (an ingredient), eggs (an ingredient), sugar (an ingredient), etc.

A preparation can therefore be nested: a preparation can contain another preparation in its composition. This is a **recursive composition**.

### Product

The finished product, intended to be sold or consumed. Composed of components, which can be ingredients and/or preparations.

Example: a strawberry tart is a product composed of a frozen tart base (an ingredient) and pastry cream (a preparation). If I want to use a homemade tart base instead, I give it a recipe. It then becomes a preparation.

## The central rule: aggregated calculation

For any **Product** (and, transitively, for any **Preparation**, since it has the same composition structure), it must be possible to automatically calculate, by recursively walking up the composition tree to the leaf ingredients:

1. **The full recipe** — the flattened list of every component actually used, with quantities scaled to the product.
2. **The aggregated nutrition facts** — the weighted sum of the nutritional values of every ingredient involved, based on its quantity in the final composition.
3. **The list of allergens** — the union of every allergen present in the composition tree, deduplicated.

This recursive calculation — Product → Preparation(s) → ... → Ingredient(s) — is the central technical value of the project. It must be tested first and exhaustively (simple cases, deep nesting, quantities, differing units).

## Simplified diagram

```
Product "Strawberry tart"
├── Ingredient "Frozen tart base"        (direct nutrition + allergens)
└── Preparation "Pastry cream"
    ├── Ingredient "Milk"                (direct nutrition + allergens)
    ├── Ingredient "Eggs"                (direct nutrition + allergens)
    └── Ingredient "Sugar"               (direct nutrition + allergens)
```

The nutrition and allergens of the strawberry tart = the aggregation of everything below it in the tree.

## Implementation notes

- **Units**: ingredients are not necessarily expressed in the same unit as their usage in a recipe (e.g. base data in grams, recipe in "1 pinch" or "1 piece") — the conversion must be explicit, never implicit.
- **Private vs. public components**: a user can create their own ingredients/preparations, visible to them only. The calculation must behave identically whether the component is public or private — no duplicated logic based on visibility.
- **A preparation can be reused across multiple products**: don't duplicate its definition at each use, reference it.
- **Cycle protection**: because preparations are reusable and composition is recursive, it is structurally possible to end up with a cycle (e.g. preparation A contains preparation B, which contains preparation A). Nothing in this domain model prevents that today. This needs a guard — at creation time (reject a composition line that would introduce a cycle) and/or at calculation time (detect and fail safely instead of looping forever) — but it is deliberately deferred: revisit this once `Preparation` composition is actually implemented in Phase 2, rather than over-designing it now.
- This document will be completed by the Documentation agent as modeling decisions are made throughout development.

### Units

To stay reliable, the tool always reasons in **mass**. Recipes are a series of lines assigning each component a quantity in **grams**. To keep recipes user-friendly nonetheless, the tool provides two mechanisms:

- **Formats**: the shape/packaging label of a component. Example: slice, box, packet, bottle, piece, etc. This is a list of read-only labels made available to users.
- **Units**: for a given component, a unit associates a format with a quantity in grams. For example, for the ingredient "ham", a user can create the unit "1 slice = 40g".

### Unrolling a recipe

When the tool needs to "unroll" a recipe:

1. Replace every unit with a quantity in grams. For example: 2 slices of 40g become 80g.
2. Replace every preparation with its list of components.

These steps repeat until the recipe contains only ingredients with no recipe of their own, expressed in grams.
