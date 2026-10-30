# Changelog

## 1.0.0 — 2026-10-29

Initial release.

- **Recipes:** a recipe post type with a photo or a YouTube or Vimeo video (embedded without
  cookies), prep and cook times, servings, ingredients, steps and nutrition facts, edited in
  Recipe Details, Recipe Video, Ingredients, Steps and Nutrition panels with repeatable rows
  (keyboard, buttons or drag) in the block and classic editors.
- **Filter types:** Meal Type, Diet, Season and Cuisine, each with its own archive pages;
  switch them off or reorder them in Settings.
- **Recipes list in the admin:** photo, time, servings and featured columns; filter by type
  and by featured; sort by featured; bulk Mark/Unmark featured.
- **Roles:** Recipe Writer and Recipe Manager. With **Grant core roles** on, Authors write
  recipes and Editors manage them; Administrators always can. Featured is set by Recipe
  Managers and Administrators, and by Editors while Grant core roles is on.
- **Writers:** profile photo, header image, about, website and social links; a writers
  listing and a page per writer, which an SEO plugin's "author archives off" setting does
  not redirect away, with their own titles under Yoast, Rank Math and All in One SEO.
- **Public pages:** the recipe archive with a filter panel, title search and pagination;
  the recipe page with ingredients, method, nutrition facts, share buttons and print; block
  and classic theme templates; pretty or plain permalinks.
- **Blocks and shortcodes:** Recipe List, Recipe Filters, Featured Recipes and Recipe Card;
  `[bfrc_recipes]`, `[bfrc_featured]` and `[bfrc_writers]`. A Recipe List filters without a
  page reload, keeps the page address in step so a link opens the same list, and works
  without JavaScript.
- **REST API:** `GET /bfrc/v1/recipes` with keyword, filters and paging.
- **Search engines:** Recipe JSON-LD on every recipe page with a photo (video recipes carry
  a VideoObject), switchable in Settings; one canonical address per writer page, alongside
  Yoast, Rank Math, All in One SEO and BlueFairy SEO Toolkit.
- **Password-protected recipes** show only their title, photo and writer on cards and keep
  their details out of the REST API until unlocked.
- **Settings:** General, Listing, Writers and Data tabs. **Grant core roles** and **Delete
  all data on uninstall** are changed by Administrators only.
- **Sample recipes:** install three draft recipes by a sample writer from the Data tab, and
  remove them again.
- **Multisite:** network activation is supported; a site created later is set up on its own.
- **WooCommerce sites:** Recipe Writers keep their access to the dashboard.
- Translation-ready, with a POT file in `languages/`.
