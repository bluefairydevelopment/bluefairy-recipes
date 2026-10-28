# BlueFairy Recipes — Features Guide

What BlueFairy Recipes 1.0.0 does, setting by setting and page by page. The names, defaults,
limits, roles and hooks quoted here are checked against the code by the plugin's own test
suite (`tests/Unit/DocsTest.php`), so a renamed setting or a changed limit fails the build
until this guide follows.

## Recipes

A recipe is a post of type `bfrc_recipe`, edited in the block editor. The post title, content (introduction), excerpt (short description), featured image (recipe photo) and author (writer) are WordPress's own. The recipe fields:

| Field | Values | Notes |
|---|---|---|
| Media type | Image or Video | default Image |
| Video URL | a YouTube or Vimeo video URL (an unlisted Vimeo link keeps its hash) | any other URL is not stored; the stored value is the canonical watch URL |
| Prep time, Cook time | whole minutes, 0–6000 | 0 = not shown |
| Servings min, Servings max | 0–999 | |
| Featured | yes/no | |
| Ingredients | up to 200 rows of measure (≤ 64 characters) and label (≤ 255) | a row without a label is dropped |
| Steps | up to 200 numbered steps | post-safe HTML allowed |
| Nutrition | calories (kcal), fat, saturated fat, carbohydrates, sugar, fiber, protein (g), sodium (mg) | numbers up to 100,000 with two decimals; `12,5` is read as 12.5 but `1,200` is refused as ambiguous; an empty field is not shown |
| Servings | | max is never below min: on save, a minimum above the maximum raises the maximum (the panel shows what you typed until the page reloads); through the REST API whichever value was written last wins and the other follows |

### Editing a recipe

The recipe fields are edited in five panels on the recipe screen, in both the block editor and the classic editor: **Recipe Details** (times, servings, Featured), **Recipe Video** (photo or video, and the video URL), **Ingredients**, **Steps** and **Nutrition**. Ingredient and step rows can be added, removed, moved with the arrow buttons or dragged by their handle into order; a recipe can hold up to 200 of each, and a new recipe starts with one blank row so it can be filled even without JavaScript. Servings are checked together as you typed them when the recipe is saved: a minimum above the maximum raises the maximum, and the panel shows your value until the page reloads. Times and servings are whole numbers; a decimal is rounded down. A video URL that is not a YouTube or Vimeo video is not saved: the block editor shows a warning under the field as soon as the save finishes, the classic editor shows it after the page reloads, and either way the warning names the URL that was refused. Fields are saved only by the recipe form itself: a quick edit, an autosave or a request that did not post the panels never clears them, and a panel that did not reach the server in full keeps its previous values. **Featured** is a curation flag: Recipe Managers and Administrators set it, and Editors while the "grant core roles" setting is on (in the panel, by bulk action, or through the REST API); writers see it read-only, their saves never change it, and restoring an older revision never changes it either. In the block editor the panels keep showing what you typed until the page is reloaded, even where the server rounded or clamped a value; the video URL is the one field checked back straight after saving.

Very long recipes: a web server accepts a limited number of form fields per request (PHP's `max_input_vars`, usually 1000). A recipe of 200 ingredients and 200 steps fits within that default; the editor warns before the form outgrows the limit, and a panel that was cut off keeps its previous values instead of being emptied.

### The Recipes list

The list shows the photo, prep and cook time, servings and a featured star for each recipe, plus a column for every filter type. Above the list you can filter by any filter type that has terms and by featured or not, and sort by the Featured column (recipes that were never marked sort as not featured). Curators get two bulk actions, **Mark featured** and **Unmark featured**, which apply to the recipes they may edit and report how many recipes actually changed.

### Writer profiles

Every user who can edit recipes has a **Recipe writer profile** section on their profile screen: a profile photo and a header image from the media library (shown to users who may upload files), an About paragraph (basic formatting kept, up to 5000 characters), a website and YouTube, Facebook, Instagram and Snapchat links (http or https with a host; anything else is cleared), and **List this writer on the public writers page**, which is on by default for the Recipe Writer role and off for everyone else. Only an image you uploaded yourself, or may edit, is accepted as a photo; any other choice keeps the previous image. Only someone who may edit that user can save the section.

### On the site

- **Recipe archive** at `/{archive slug}/` (default `/recipes/`): a card grid (photo, title, prep and cook time, servings, writer, short description) with a filter panel above it: one dropdown per filter type that has terms, a title keyword search, Filter, and Reset once something is filtered. A changed dropdown or a pause in typing applies the filters after a moment and puts the keyboard focus back on the control you used; the Filter button applies them at once, and the form works without JavaScript. A writer's own page lists all their recipes and takes no filters. A password-protected recipe's card shows its title, photo and writer only. Pages follow your "Recipes per page" setting. Each filter type also has its own page, `/recipes/meal-type/dinner/` and so on, with the same panel.
- **Recipe page**: title, writer and date, timings and servings, the filter-type tags, the photo or the embedded video (YouTube or Vimeo, loaded without cookies), the short description, your text, Ingredients, Method, Nutrition (per serving; only the values you entered), share buttons (Facebook, X, Pinterest with the recipe photo) and a Print button. The print layout keeps the recipe, its title and timings, and drops the site's header, footer, navigation, filters and buttons. A password-protected recipe shows the password form and nothing else until it is unlocked.
- **Writer pages**: `/recipes/chef/` lists every public writer (name order, 24 per page, photo or Gravatar, recipe count, a line of their About text); `/recipes/chef/{nicename}/` (the user's URL name, normally their username) shows a writer's header image, photo, About, links and their recipes, with a feed at `/recipes/chef/{nicename}/feed/`. Each page names its own canonical address, and the query-string spellings of the same page redirect to it (with Yoast, Rank Math or All in One SEO active, the plugin hands that address to the SEO plugin's canonical instead of printing a second one; with BlueFairy SEO Toolkit 1.1.2 or later it hands the address to the toolkit's `og:url`, and when SEO Toolkit Pro prints the canonical it prints none of its own; SEO Toolkit is not required; another SEO plugin that prints its own canonical on these pages should take the writer URL from the plugin, `Templates::canonical_url()`, and may return an empty string from `bfrc_writer_canonical` to silence the plugin's own tag). A writer has a public page only when they are listed (Recipe Writers by default; anyone else by switching it on in their profile) and have at least one published recipe. Everyone else's writer URL is a 404, and their recipes show the name without a link. "Browse recipe writers" appears on the archive while the listing is on. Note that a listed writer's display name and username appear in public URLs, as on any WordPress author page, and that switching the listing off hides a writer from these pages and from the users sitemap, not from WordPress's own author archive.
- **Themes**: block themes get three templates (Single Recipe, Recipes Archive, Recipe Writers) you can arrange in the Site Editor; the recipe block inside them renders on the site and, on WordPress 7.0 or newer, previews in the editor: the Single Recipe template previews your newest published recipe that has no password (or a short note until one is published), the Recipes Archive template previews the filter panel and a grid of your newest recipes, both styled as on the site (older WordPress versions show a placeholder: leave it in place). The writer pages use the Recipe Writers template, which previews the writers list, so a theme's own recipes-archive template never catches them. Classic themes get the plugin's templates, which you can override by copying them to `{your theme}/bluefairy-recipes/single-recipe.php` or `archive-recipe.php`, or by shipping your own `single-bfrc_recipe.php` / `archive-bfrc_recipe.php` (the writer pages use the plugin's archive template, or your `bluefairy-recipes/archive-recipe.php`). Inserting the Single Recipe or Recipes Archive block into a recipe's own text renders nothing there, and in the post editor it shows a note saying so instead of another recipe's page or a grid. A password-protected recipe keeps its ingredients, method and nutrition out of the REST API as well as off the page until it is unlocked.
- With the plugin **disabled**, every recipe URL, filter-type page and writer page is a 404.

## Filter types (taxonomies)

Recipes are browsed by four filter types, each a taxonomy under the recipe archive:

| Filter type | Taxonomy | URL | Starter terms (seeded once, on activation, only into an empty taxonomy) |
|---|---|---|---|
| Meal Types | `bfrc_meal_type` | `/recipes/meal-type/…` | Breakfast, Lunch, Dinner, Dessert, Snack |
| Diets | `bfrc_diet` | `/recipes/diet/…` | Vegetarian, Vegan, Gluten-Free, Dairy-Free |
| Seasons | `bfrc_season` | `/recipes/season/…` | Spring, Summer, Autumn, Winter |
| Cuisines | `bfrc_cuisine` | `/recipes/cuisine/…` | Italian, Mexican, Asian, American, Mediterranean |

Each can be switched off and reordered in Settings. A switched-off filter type keeps its terms, its admin screen and its column in the Recipes list, but has no public archive, no select in the filter form and no filter on the plugin's REST endpoint; its terms stay readable through WordPress's own taxonomy route (`/wp/v2/bfrc_season`), which the recipe editor's taxonomy panel uses. The Recipe Manager role manages terms; writers assign them. A filter type's name may not be a route under the archive (`chef`, `page`, a feed name).

## SEO

Every recipe page with a photo carries Recipe JSON-LD for Google (name, image, writer, dates,
description, times, servings, meal types, cuisines, keywords, diets, ingredients, steps,
nutrition, and the video for a video recipe), unless Rich snippets is off or the recipe is
password-protected. A recipe without a photo gets none: Google requires an image. Recipes and
the filter-type pages are in the site's sitemap while the plugin is enabled.

## Settings

Stored in one option, `bfrc_settings`, edited at Recipes → Settings (General, Listing,
Writers and Data tabs; the Pro add-on adds its own). The values below are the defaults the
plugin installs.

| Setting | Default | Notes |
|---|---|---|
| Enabled | On | Off: recipes, the archive and the filter archives return 404 (previews too; with pretty permalinks), recipes leave search, the sitemap and the public REST API; the admin and the editor keep working |
| Rich snippets | On | |
| Archive slug | `recipes` | Changing it flushes rewrite rules on the next page load. Routes WordPress owns (`feed`, `page`, `wp-json`, a year, …) are refused |
| Archive title | Recipes | |
| Recipes per page | 12 | 1–100 |
| Social share | On | Facebook, X and Pinterest each have their own switch |
| Writer listing | On | |
| Grant core roles | On | Authors can write recipes and Editors can manage them; turning it off takes those capabilities back. Administrators can always do everything |
| Delete all data on uninstall | Off | |
| Sample recipes | not installed | The Data tab installs three sample recipes (drafts, with illustrative placeholder images) by a sample writer, and removes them again. Installing creates the writer account, so it needs the right to create users (a leftover sample writer account from an earlier install is reused; an account with that name that is not the plugin's own is refused); removing deletes it (unless you renamed or promoted it), so it needs the right to delete users (on a network, to remove users), and it deletes the sample recipes permanently even if you edited or published them. Deleting all data on uninstall removes the sample images and the account too (unless you renamed or promoted it) |

The settings live at Recipes → Settings in four tabs (General, Listing, Writers, Data). Each
tab saves on its own. The General tab also switches each filter type on or off and orders
them. The plugins row carries a Settings link and a "Get Pro" link; the Get Pro link and a
note at the foot of the settings page name what BlueFairy Recipes Pro adds, and both
disappear when the add-on is active.

## Roles

| Role | Can |
|---|---|
| Recipe Writer (`bfrc_writer`) | create, edit, publish and delete their own recipes; assign terms; upload files |
| Recipe Manager (`bfrc_recipe_manager`) | everything a writer can, for every recipe; manage the recipe taxonomies; change settings |

Administrators can always change settings.

## Blocks and shortcodes

| Block | Shortcode | What it shows |
|---|---|---|
| Recipe List | `[bfrc_recipes per_page="12" show_filters="1" show_pagination="1" diet="vegan"]` | A page of recipes with the filter form and pagination. Recipes per page 0 (or absent) uses the setting. A fixed filter (one attribute per filter type: `meal_type`, `diet`, `season`, `cuisine`, …) narrows the list and removes that select from the form. Changing a filter, or pausing while typing, updates the list without a reload (600 ms); the page's address follows, so a reload or a shared link opens the same list. Without JavaScript the form and the page links work as plain links |
| Recipe Filters | — | The filter form alone, for a Recipe List block placed elsewhere on the page (set that list's "Show the filter form" off). A select for a filter type that list fixes hides itself once the page scripts run |
| Featured Recipes | `[bfrc_featured title="Featured recipes" mode="featured" count="4"]` | The featured recipes, or with `mode="recent"` the newest ones, 1 to 12 cards. Shows nothing when there is nothing to show |
| Recipe Card | — | One recipe as a card, chosen by title in the block settings |
| Recipe Writers (template) | `[bfrc_writers]` | The writers listing; on a page it is paged by `?bfrc_page=N` of that page |

A shortcode renders exactly the markup of its block (less the block's wrapper element). One
list per page is the one the filters update without a reload: the first rendered, which is a
Recipe List block before any `[bfrc_recipes]` shortcode in the same content, whatever their
order on the page; another list shows the page's address with a plain form and plain links. A
Recipe Filters block placed above the list it drives hides a fixed filter's select only once
the page scripts run; place it after the list, or use the list's own form, if visitors
without JavaScript matter. The browser's Back button does not step through filter changes
(the address is updated in place). A list on page 2 or later of a post split with "Page break"
is not supported. A password-protected recipe is a locked card (title, photo, writer) in every
list.

## REST API

`GET /wp-json/bfrc/v1/recipes` is public and read-only. Arguments: `s` (title keyword, up to
100 characters), `page` (1–1000), `per_page` (1–100; 101 is refused), `filter[diet]=vegan`
(one term slug per filter type; any other key under `filter`, such as `filter[page]`, is
refused). Each item carries `id`, `title`, `url`, `thumb`, `writer`, `times`, `excerpt` and
`html` (the card). Totals are in the `X-WP-Total` and `X-WP-TotalPages` headers. Anonymous
responses may be cached for 60 seconds. While the plugin is disabled the list is empty.

## Extending

| Hook | Kind | Purpose |
|---|---|---|
| `bfrc_loaded` | action | Fired once the settings exist and before the content model is wired; the Pro add-on attaches its filters here |
| `bfrc_booted` | action | Fired after every subsystem is wired |
| `bfrc_is_pro` | filter | Return true when a Pro add-on is running |
| `bfrc_filter_taxonomies` | filter | Add filter types (code ⇒ label, singular, sort order) |
| `bfrc_ingredient_types` | filter | Add ingredient row types beyond `plain` |
| `bfrc_ingredient_row_fields` | filter | Extra fields, with sanitisers, for an ingredient row type |
| `bfrc_reserved_slugs` | filter | More recipe slugs to refuse |
| `bfrc_recipe_meta_boxes` | action | Add your own panels to the recipe edit screen (receives the post) |
| `bfrc_ingredient_type_labels` | filter | Name your ingredient row types in the editor |
| `bfrc_ingredient_row_field_labels` | filter | Label the extra fields of an ingredient row type |
| `bfrc_recipe_saved` | action | The recipe form was saved; receives the post id and every value written |
| `bfrc_schema_data` | filter | The Recipe JSON-LD before it is printed (add `aggregateRating`, say) |
| `bfrc_settings_tabs` | filter | Add a settings tab (label and a render callback) |
| `bfrc_settings_page_footer` | action | Print below the settings form, on every tab |
| `bfrc_settings_saved` | action | A settings tab was saved; receives the stored settings and the raw form values |
| `bfrc_admin_menu_items` | filter | Add submenus under Recipes |
| `bfrc_archive_query` | action | The public recipe list query, after per-page, search and filters are applied |
| `bfrc_list_query_args` | filter | The query arguments of a list that is not the archive page: the REST endpoint, the Recipe List block, `[bfrc_recipes]` (receives the arguments, the normalised request and the context) |
| `bfrc_ingredient_label_html` | filter | The label markup of one ingredient row on the recipe page |
| `bfrc_render_ingredient_actions` | action | After the ingredient list on the recipe page |
| `bfrc_single_after_steps` | action | After the method (also when a recipe has no steps) |
| `bfrc_single_after_nutrition` | action | After the nutrition facts (also when there are none) |
| `bfrc_single_footer` | action | At the end of the recipe page |
| `bfrc_writer_canonical` | filter | The canonical address of a writer page or the writers listing before it is printed (an empty string prints none) or, with Yoast, Rank Math, All in One SEO or BlueFairy SEO Toolkit active, before it is handed to that plugin (an empty string hands the SEO plugin its own value back) |

The four recipe-page actions (`bfrc_render_ingredient_actions`, `bfrc_single_after_steps`,
`bfrc_single_after_nutrition`, `bfrc_single_footer`) receive the recipe id; whatever the
callback prints is placed at that point of the page.

## Differences from the Magento extension

BlueFairy Recipes is the WordPress half of the same product as the BlueFairy Recipes extension
for Magento 2. The two share the recipe model, the four filter types, writer pages, Recipe
JSON-LD, sharing and the featured block. Where WordPress already does the job, the plugin
leaves it to WordPress:

- **No "Show in navigation menu" setting.** WordPress menus belong to you: add the recipe
  archive to a Navigation block with a custom link to `/recipes/` (or your archive slug); a
  theme with a menu screen also lists a "Recipe Archives" item under Appearance → Menus →
  Recipes once a recipe is published.
- **No ".html" URL postfix setting.** The archive slug is configurable (default `recipes`);
  recipe addresses follow WordPress's permalink structure, and WordPress's own old-slug
  redirect takes over the 301 a changed URL key needed in Magento.
- **No per-recipe meta title, meta description or meta keywords fields.** An SEO plugin owns
  the page head; without one, a recipe gets WordPress's own title tag (recipe title and site
  name) and nothing more. The Recipe JSON-LD is printed by the plugin either way.
- **Writer "Name" and "Nickname" are the WordPress display name**, and the writer "Info text
  colour" is left to the theme. There is no "Admin user" link on a writer: a writer is a
  user.
- **No images on filter options** (the Magento storefront did not render them either) and
  **no recipe-to-product sort order** (the Pro add-on's product links render in the order they
  were linked).
- **No Instagram share button.** Instagram has no web share address; the Magento toggle
  rendered a profile link, not a share. A writer's own Instagram link stays on their profile.
- **No store-view scope.** On a multisite network each site is its own recipe site, with its
  own settings and recipes: network-activate the plugin or activate it on each site that
  needs it, and a site created later is set up on its own.
