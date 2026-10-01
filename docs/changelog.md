# Changelog

> Maintained by the Documentation agent (`/doc`) after each merge to `main`.

## 2026-10-01

### New

- **The ingredient database is in place.** About 1,900 everyday ingredients from the original spreadsheet now live in Sharlotte, each with its nutrition values per 100 g and its allergens (both "contains" and "may contain traces of"). There's no screen to browse them yet. That's coming in a later version.
- **Ingredients are sorted into 20 categories** (fruits, vegetables, meat, fish, dairy, spices and herbs, drinks, meat and cheese alternatives, and more), each with a short description of what belongs in it.
- **Fresh, frozen and room-temperature versions of the same food are kept apart**, because their nutrition values differ and one can't simply replace the other in a recipe.
- **Ingredients can be flagged for review.** If an ingredient's data looks doubtful (odd values, a confusing name), it can be marked so it gets checked and corrected later.

### Improvements

- **Nutrition values are now always complete and exact.** Every ingredient has all seven essential nutrition values: calories, fat, saturated fat, carbohydrates, sugars, protein and salt. Calories keep their decimals (72.8 kcal stays 72.8 instead of being rounded to 73), so recipe totals will be more accurate. Fibre and water are still optional: when they're not known, Sharlotte says so instead of pretending they're zero.
