# Changelog

> Maintained by the Documentation agent (`/doc`) after each merge to `main`.

## 2026-10-01

### New

- **The ingredient database is in place.** About 1,900 everyday ingredients from the original spreadsheet now live in Sharlotte, each with its nutrition values per 100 g and its allergens (both "contains" and "may contain traces of"). There's no screen to browse them yet. That's coming in a later version.
- **Ingredients are sorted into 20 categories** (fruits, vegetables, meat, fish, dairy, spices and herbs, drinks, meat and cheese alternatives, and more), each with a short description of what belongs in it.
- **Fresh, frozen and room-temperature versions of the same food are kept apart**, because their nutrition values differ and one can't simply replace the other in a recipe.
- **Ingredients can be flagged for review.** If an ingredient's data looks doubtful (odd values, a confusing name), it can be marked so it gets checked and corrected later.
- **Groundwork for everyday quantities.** Sharlotte can now remember how much one slice, bottle, box, pack, piece or clove of a given ingredient weighs, so you'll later be able to write "2 slices of ham" in a recipe instead of weighing everything in grams. You can't add these yourself yet. That will come with the recipe screens.
- **Groundwork for your own recipes.** Sharlotte can now store recipes made of ingredients and of your own preparations. For example, a tart can use the pastry cream you wrote down separately. Each quantity can be in grams or in a unit such as "1 slice of ham = 40 g", and your own preparations can have units too (like "1 homemade shortcrust base = 300 g"). There are no screens to write recipes yet. Those will come in a later version.
- **A preparation you use can't be deleted by accident.** If one of your recipes uses a preparation, an ingredient or a unit, Sharlotte won't let you delete it while that recipe still needs it.
- **Deleting your account also deletes all your recipes**, along with your preparations and anything else that's yours.

### Improvements

- **Nutrition values are now always complete and exact.** Every ingredient has all seven essential nutrition values: calories, fat, saturated fat, carbohydrates, sugars, protein and salt. Calories keep their decimals (72.8 kcal stays 72.8 instead of being rounded to 73), so recipe totals will be more accurate. Fibre and water are still optional: when they're not known, Sharlotte says so instead of pretending they're zero.
