# Changelog

## 1.0.0 — unreleased

- Initial release.
- The Print recipe button appears only where it can work (with JavaScript).
- Multisite: network activation is supported; a site created after it is set up on its
  own.
- Recipe edit screen: Recipe Details, Recipe Video, Ingredients, Steps and Nutrition panels
  with repeatable rows (keyboard, buttons or drag), in the block and classic editors; a
  refused video URL is reported; a panel that did not reach the server keeps its values.
- Featured is set by Recipe Managers and Administrators, and by Editors while "Grant core roles"
  is on; writers see it read-only.
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
- Blocks: Recipe List (per page, fixed filters, filter form and pagination switches), Recipe
  Filters, Featured Recipes (featured or newest, 1–12) and Recipe Card; shortcodes
  `[bfrc_recipes]`, `[bfrc_featured]` and `[bfrc_writers]` with the same markup.
- Filtering a Recipe List updates it without a reload (600 ms after the last change); the
  page address follows so a reload or a shared link opens the same list; plain links and a
  plain form without JavaScript; a slow answer never overwrites a newer one; focus stays in
  the form, or moves to the new current page after a page change; the first list rendered on
  a page is the live one.
- Inside the editor the blocks' previews are inert: a click in the preview's form cannot
  navigate the editor.
- Public REST endpoint `GET /bfrc/v1/recipes` (keyword, filters, page, per page; bounded;
  cards included; a locked recipe is its locked card for every caller; empty while disabled).
- A view block pasted into a post's text shows a note in the editor instead of another
  recipe's page or a grid.
- Every cached recipe list carries a change counter: a saved recipe, field, term or term
  rename is seen at once.
- Recipe JSON-LD on every recipe page with a photo (Google's Recipe properties; video
  recipes carry a VideoObject); none when the Rich snippets setting is off or the recipe is password-protected.
- Recipes → Settings: General, Listing, Writers and Data tabs; filter types can be switched
  off and ordered.
- Sample recipes: install three draft recipes with illustrative placeholder images by a sample writer from the Data
  tab, and remove them again.
- A "Get Pro" link and a note on the settings page name what the Pro add-on adds; both go
  when it is active.
- readme.txt names the two video services (YouTube, Vimeo) and Gravatar and what they load; a POT file
  ships in `languages/`.
- Documentation: the features guide, the readme and this changelog describe 1.0.0 in full, and a
  test keeps the names, defaults, limits, roles and hooks they quote in step with the code.
