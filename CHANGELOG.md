# Changelog

## 1.0.0 — unreleased

- Initial release. Entries are written as each feature lands.
- Recipe edit screen: Recipe Details, Recipe Video, Ingredients, Steps and Nutrition panels
  with repeatable rows (keyboard, buttons or drag), in the block and classic editors; a
  refused video URL is reported; a panel that did not reach the server keeps its values.
- Featured is set by Recipe Managers, Editors and Administrators; writers see it read-only.
- Recipes list: photo, time, servings and featured columns; filter by type and by featured;
  sort by featured; bulk Mark/Unmark featured for curators.
- Writer profile fields: profile photo, header image, about, website and social links,
  and the writer-listing switch.
- Public pages: recipe archive with filter panel, title search and pagination; filter-type
  pages; recipe page with photo or cookie-less video, ingredients, method, nutrition facts,
  share buttons and print; writers listing and writer pages; block and classic templates.
- A password-protected recipe shows only its title, photo and writer on cards and keeps its
  fields out of the REST API until unlocked; writer pages have one canonical address; the
  Single Recipe template previews a recipe in the Site Editor.
- The recipe blocks render nothing inside a recipe's own text; writer pages work with Yoast,
  Rank Math and All in One SEO canonicals, custom pagination bases and plain permalinks;
  Recipe Writers can reach the dashboard on WooCommerce sites; keyboard focus returns to the
  filter control that was used; pagination lays out as a row; filters fill the width on phones;
  the stylesheet loads on recipe views only; the recipe blocks render only on recipe pages,
  never inside feeds or API items; a password-protected recipe's API item is never cached.
