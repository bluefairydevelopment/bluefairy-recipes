# BlueFairy Recipes — architecture and module contracts

Base plugin (`bluefairy-recipes`, namespace `BlueFairy\Recipes`, prefix `bfrc_`).
The Pro add-on (`bluefairy-recipes-pro`, `BlueFairy\RecipesPro`, `bfrcp_`) hooks the
seams listed under **Extension seams** and never ships inside this plugin.

## Fixed conventions

- `declare(strict_types=1);` in every PHP file, directly after the opening tag (in the
  entry file, after the plugin header docblock WordPress requires there). WPCS
  (`phpcs.xml.dist`), PHPStan level 6 (`phpstan.neon`), PHPUnit 10 (`phpunit.xml.dist`).
  Tabs. Yoda conditions. Every function has a docblock with `@param` for every parameter.
- PSR-4 under `includes/`. Instance-based services wired in `Plugin::boot()` and exposed
  through `Plugin::service( $id )`; `Plugin::*`, `Installer::*`, `Capabilities::*` and the
  `Settings::sanitize_*`/`to_bool` helpers are static. `Plugin::reset()` is a test seam
  (`@internal`).
- Option writes whose outcome matters go through the trait
  `Support\WritesOptions::write_option( $option, $value, $autoload ): bool`:
  `update_option()` returns false both for a refused write and for an unchanged value, so on
  false the stored value is read back and compared in its stored form (no row means failed).
- **No custom tables.** Recipes are a post type, browse filters are taxonomies, recipe data
  is registered post meta, writer profiles are user meta. Direct SQL exists only in
  `uninstall.php`, where the post type and taxonomies are not registered.
- Every list query is bounded (`posts_per_page`, `number`, `LIMIT`, keyset paging in
  uninstall).
- Admin: every form handler checks the capability first, then `check_admin_referer(
  'bfrc_<action>' )`. Output escaped at the boundary. Argument-less action callbacks have
  no typed first parameter (WordPress passes `''`); a test checks this by reflection.
- Text domain `bluefairy-recipes`; no `load_plugin_textdomain` (WordPress.org loads
  translations). No `Update URI`, no updater: the free plugin updates from WordPress.org.
  Role display names are stored untranslated, as core stores its own.
- Prefix every global (`bfrc_`), hook (`bfrc_`), option (`bfrc_`), transient (`bfrc_`),
  AJAX action (`bfrc_ajax_`), handle (`bfrc-`), capability (`bfrc_manage_recipes` and the
  `*_bfrc_recipe(s)` set), REST namespace `bfrc/v1`, object-cache group `bfrc`.
- The entry file returns early when `BFRC_VERSION` is already defined (a second copy of
  the plugin must not fatal) and its autoloader validates the class name before it becomes
  a path.

## Boot order

1. `bluefairy-recipes.php` (file scope): constants, autoloader, activation/deactivation
   hooks, `bfrc_plugin()`, `plugins_loaded` → `Plugin::boot()` at priority 10.
2. `Plugin::boot()`: `Installer::maybe_upgrade()`; hooks `Installer::maybe_flush_rewrite`
   on `init` 99; creates `Settings` (service `settings`); **`do_action( 'bfrc_loaded' )`**;
   wires the content model (services `content.taxonomies` → `init` 5, `content.post_type` →
   `init` 6, `content.meta` → `init` 7, `content.slugs` → `wp_unique_post_slug`); later
   subsystems; `do_action( 'bfrc_booted' )`.
3. Pro hooks `bfrc_loaded` at file scope, so its filters are in place before anything here
   reads them, and sets `bfrc_is_pro` to true from its own boot after `base_ok()`.

Per request, on a provisioned site: three autoloaded option reads (`bfrc_db_version` and
`bfrc_version` in `maybe_upgrade()`, `bfrc_settings` at `init` 5) and the flush flag on `init`
99, all from the options cache, so no query. Nothing is written. A site with no
`bfrc_db_version` (a network sub-site, a copied install) provisions itself at
`plugins_loaded`: the taxonomies are then registered without rewrite rules (core has not
built `$wp_rewrite` yet and would fatal) and with their English labels (no text domain is
loaded before `init`); the registration at `init` 5 replaces both and the flush flag writes
the rules. Starter terms seeded on that path are written in English and stay so (term names
are data; a site owner renames them). On a site with plain permalinks the Enabled switch
cannot 404 `?bfrc_recipe=…` (the query var is dropped and the home page answers); pretty
permalinks are the supported configuration and the readme says so.

## Settings

One option, `bfrc_settings` (autoloaded), read through the shared instance
`Plugin::service( 'settings' )`. `Settings::DEFAULTS` is the whole vocabulary (an add-on
keeps its own option; keys outside the vocabulary are dropped). `patch()` merges the
`taxonomies` map one level down too. The settings screen (a later milestone) must save
through `update()`, not through `options.php`, so the side effects below run.
Every read is normalised (typed, bounded, system taxonomies always present), so a value
written around the class cannot reach a consumer as the wrong type. Two writers:
`update()` has form semantics (an absent checkbox is unticked) for the settings screen;
`patch()` has merge semantics for code that changes one key. A changed `archive_slug`
raises the flush flag; a flipped `grant_core_roles` grants or revokes the core roles'
recipe capabilities. Both only when the write reached the database.

The archive slug is `sanitize_title()` of the input, at most 64 bytes cut on a whole
percent-encoded character, never empty, never one of `Settings::RESERVED_SLUGS` (routes
WordPress owns) or a four-digit year. Custom filter-type codes match
`Settings::TAXONOMY_CODE_PATTERN` (`^[a-z][a-z0-9_]{1,26}\z`, so `bfrc_{code}` fits
core's 32-character taxonomy-name limit); at most `MAX_CUSTOM_TAXONOMIES` (50) are kept.

## Content model (milestone A2)

| What | Where | Notes |
|---|---|---|
| Recipes | post type `bfrc_recipe` (`Content\PostType`) | public, block editor, excerpt = short description, featured image = recipe photo, author, revisions; archive at the `archive_slug` setting; `capability_type bfrc_recipe` + `map_meta_cap`; `delete_with_user` off (a deleted writer's recipes keep a dangling author id; the A4 renderer must cope with `get_userdata()` returning false). **Enabled off:** still registered for the admin, but no rewrite rules or query var (a matched rule whose query var core drops would otherwise serve the home page with 200), no archive, excluded from search and from `wp_sitemaps_post_types`, and the core REST routes for recipes and recipe taxonomies (`/wp/v2/bfrc_…`) answer `rest_no_route` (404) to anyone who cannot edit recipes, gated on `rest_pre_dispatch` after authentication so cookie and application-password clients are judged alike, and `/wp/v2/search` drops recipes for the same users via `rest_post_search_query` (`show_in_rest` itself stays true: the block editor needs the routes, and asking for the user at `init` would fix it before other authenticators run). Previews 404 for everyone while disabled. Flipping the switch, the archive slug or a taxonomy switch raises the flush flag. |
| Filter types | taxonomies `bfrc_{code}` (`Content\Taxonomies`) | four system codes (`meal_type`, `diet`, `season`, `cuisine`) plus up to 50 valid definitions from `bfrc_filter_taxonomies` (attach it on `bfrc_loaded`: definitions are read at `init` 5 and cached for the request; `flush()` drops the cache and a settings write calls it); a code that is a system code, a route name (`chef`, `page`, feed names) or a reserved site route is refused; switched and ordered by the `taxonomies` setting; rewrite `{archive}/{code-with-dashes}`; term capabilities `manage/edit/delete/assign_bfrc_terms`. A disabled type (or a disabled plugin) registers with `public false`, so the admin keeps its terms; its REST routes are closed to non-editors by the same dispatch gate while the plugin is disabled. Starter terms seeded on activation only into an empty system taxonomy (`ensure_default_terms()`); labels and starter terms are translated once `init` has run. **Rewrite order (A4 constraint):** taxonomy rules register at `init` 5, before the post type at 6, which is what keeps `recipes/meal-type/x` from matching the recipe attachment rule; the writer routes `{archive}/chef/…` must likewise be added before the post type's rules or with `top` priority. |
| Recipe fields | registered post meta (`Content\Meta`) | `_bfrc_media_type`, `_bfrc_video_url`, `_bfrc_prep_minutes`, `_bfrc_cook_minutes`, `_bfrc_servings_min`, `_bfrc_servings_max`, `_bfrc_featured`, `_bfrc_ingredients`, `_bfrc_steps`, `_bfrc_nutrition`; each single, REST schema (with `maxItems`/`maxLength` where they apply; no `format: uri` on the video so `''` clears it), `revisions_enabled` (except Featured), `auth_callback` = `edit_post` for the user core names (Featured also needs `edit_others_bfrc_recipes`), one sanitiser (bounds: minutes 0–6000, servings 0–999, 200 rows, measure 64, label 255; nutrition 0–100000 with two decimals or null; a decimal comma is accepted except `1,200`, which is ambiguous and refused; REST clients send a dot, since the schema is validated before the sanitiser). **Servings max ≥ min** is owned here on `added_post_meta`/`updated_post_meta`: the key written last wins and the other follows, on every write path (the edit form first resolves its posted pair itself; see the A3 save handler). Revisions keep every meta key but Featured, so revision storage grows with ingredient rows; WordPress's default (unlimited revisions) applies and `wp_revisions_to_keep` can cap it. |
| Ingredient rows | `{ measure, label, type, …extra }` | `type` is `plain` unless `bfrc_ingredient_types` adds more; extra fields and their sanitisers come from `bfrc_ingredient_row_fields( $type )` and nothing else survives. |
| Video | `Support\VideoUrl` | YouTube (11-character id) and Vimeo (numeric id, with the unlisted-video hash when present; channel, group and album URLs accepted) only; anything else is stored as `''` (the A3 editor tells the writer); stored as the canonical watch URL; embedded as an iframe from the validated tokens via `youtube-nocookie.com` / `player.vimeo.com`; no oEmbed, no server-side fetch. |
| Reserved recipe slugs | `Content\Slugs` on `wp_unique_post_slug` | `chef`, `submit`, `page`, feed bases, `embed`, `trackback`, every taxonomy code (dashed and underscored) and `bfrc_reserved_slugs`; a reserved slug gets the first free `-N` suffix (N ≤ 100, then a random one). Core asks the filter only for published and scheduled posts, so a draft keeps a reserved slug until it is published (as core does for duplicates). |
| Settings | option `bfrc_settings` (autoload) | see above |
| Versions | options `bfrc_version`, `bfrc_db_version` (autoload) | `Installer::maybe_upgrade()` compares on every boot |
| Rewrite flag | option `bfrc_flush_rewrite` (autoload, always present, `0`/`1`) | raised by activation and by a changed `archive_slug` (only when the version write succeeded); consumed once on `init` 99. An option rather than a transient: free to read, cannot be evicted or expire |
| Roles | `bfrc_writer`, `bfrc_recipe_manager` | `Capabilities::WRITER_CAPS`, `MANAGER_EXTRA_CAPS`; core roles granted per `core_grants()` while `grant_core_roles` is on (turning it off takes the capabilities back from Authors and Editors; administrators keep everything) |

`Installer::OPTIONS` must equal the option list in `uninstall.php` in both directions, as
must the capability list, the role list and the core-role list (one test enforces all four).

## Admin editing (milestone A3)

All admin classes live in `includes/Admin/` and are wired by `Plugin::boot()` only when
`is_admin()` is true (services `admin.editor`, `admin.list_table`, `admin.writer_profile`).
REST, cron and WP-CLI writes never need them: the registered meta and its `auth_callback`
live in `Content\Meta`, which is wired unconditionally.

| What | Where | Notes |
|---|---|---|
| Recipe edit screen | `Admin\RecipeEditor` | Five PHP meta boxes (`bfrc-details`, `bfrc-media`, `bfrc-ingredients`, `bfrc-steps`, `bfrc-nutrition`) registered on `add_meta_boxes_bfrc_recipe`, so they work in the block and the classic editor alike; then `do_action( 'bfrc_recipe_meta_boxes', $post )` for the add-on. **Every box ends with the nonce and a hidden sentinel** `bfrc[_has][{section}]=1` (the nonce is repeated under one name in every box, so removing or hiding any one box, Details included, never discards the others' edits); a section is written only when its sentinel arrived, so a box another plugin removed, or a form PHP truncated at `max_input_vars`, keeps its stored values. An add-on box should end with its own `bfrc[_has][name]` sentinel and read it from `$raw['_has']` in `bfrc_recipe_saved`. Repeatable rows are a `<template>` per box plus `assets/admin/recipe-editor.js` (DOM wiring, exported as `init( document, window )` and tested under jsdom) and `assets/admin/recipe-rows.js` (pure helpers: renumbering `bfrc[ingredients][N][…]`, moving, the row ceiling, label templates, the field-count rule; `node --test`). Drag starts from the handle cell only (a draggable row turns text selection into a drag). Rows carry `aria-label`s ("Ingredient 3 name", "Step 2") that the script renumbers; moves are announced through `wp.a11y.speak`. An empty recipe renders one blank row, so a recipe can be filled without JavaScript. No `min`/`max`/`step`/`type=url` HTML constraints (`step="any"` on every number): the sanitisers clamp and floor, the server says what it refused, and a constraint-invalid field inside a collapsed or Screen-Options-hidden classic-editor box would block Publish with no message (the script also opens such a box on `invalid`). The block editor never re-renders the boxes after a save, so a floored or clamped value shows as typed until the page is reloaded; only the video URL is read back. The script warns inline when the enabled fields in our boxes plus a 120-field margin would exceed `max_input_vars` (localised from `ini_get`). Assets load on `post.php`/`post-new.php` for `bfrc_recipe` only; `wp-data` + `wp-api-fetch` only in the block editor. |
| Ingredient row types | `RecipeEditor::ingredient_type_labels()`, `ingredient_row_field_labels()` | A type selector appears only when `bfrc_ingredient_types` adds a type. Labels come from `bfrc_ingredient_type_labels` (fallback `ucfirst`) and `bfrc_ingredient_row_field_labels( $labels, $type )` (fallback: the field name); the fields themselves still come from `Meta::ingredient_row_fields()`. Every type's extra fields are rendered on every row, but the other types' fields are `hidden` **and `disabled`**, so only the chosen type's fields are posted (that keeps a 200-row recipe at about 3 fields per row and lets two types share a field name without a collision). Extra fields are plain text inputs; a richer control (a product picker) is the add-on's to render in its own box. |
| Save handler | `RecipeEditor::save()` on `save_post_bfrc_recipe` | Returns on another post type, autosaves and revisions, and when the nonce field is absent (REST, quick edit, a programmatic save: nothing is wiped by a request that did not post the boxes). Then **capability first** (`edit_post`), then the nonce (`bfrc_recipe_meta`, refused unless a string), then each section whose sentinel arrived through the same `Content\Meta` sanitisers REST uses, then `update_post_meta` **with `wp_slash()`**: core's `update_metadata()` unslashes what it is given, so an unslashed clean value would lose every backslash (the harness stubs unslash too, so a test shows it). Form semantics apply within an arrived section: an unticked Featured box and a box with every row removed clear the stored value. **Servings are judged as a whole and only from the posted pair** (a minimum above the maximum raises the maximum; `0` max = not set), never against the stored values: the block editor re-posts the unchanged form on every Update, so the rule must be idempotent. `Content\Meta::reconcile_servings` still guards the REST path. **Featured is curation** (`Meta::can_curate()`: `edit_others_bfrc_recipes`, held by Recipe Managers and Administrators, and by Editors while the core-role grant is on): a writer sees it read-only, their save never touches it, `$raw['featured']` is stripped before `bfrc_recipe_saved` for them, the REST `auth_callback` refuses the key for them, and the key is **not revisioned** (`revisions_enabled` false), because core's revision restore copies meta without consulting the auth callback and would let a writer re-apply or wipe a curator's flag. A non-empty video URL that normalises to `''` is still stored as `''` (the writer asked for a change) and the writer is told: in the classic editor by a per-user transient (`bfrc_editor_notice_{uid}`, 5 minutes, the typed URL capped at 300 characters) shown once on the recipe edit screen only; in the block editor (a `meta-box-loader` request, which never reloads) no transient is left and the script reads `GET /wp/v2/bfrc_recipe/{id}?context=edit&_fields=meta` back after `isSavingMetaBoxes` falls and shows the same message inline. An empty field is a deliberate clear and raises nothing. Finally `do_action( 'bfrc_recipe_saved', $post_id, $clean, $raw )`. |
| Recipes list | `Admin\ListTable` | Columns `bfrc_photo` (after the checkbox), `bfrc_times` (prep only / cook only / both), `bfrc_servings`, `bfrc_featured` (before the date); taxonomy columns come from core (`show_admin_column`). `filters()` primes the thumbnail cache for the page (`update_post_thumbnail_cache`; core's posts list does not) and renders one labelled `wp_dropdown_categories` per enabled taxonomy that has terms (`hide_empty` false so terms used only on drafts appear, `hide_if_empty`, `number` 500, label only when a select is rendered; an array query parameter is ignored since core's `sanitize_title()` fatals on arrays, and core's "All" option posts `0`, which is no filter) and a labelled featured select (`bfrc_featured` = `1`/`0`). **Nothing about Featured ever joins postmeta in a list query.** `Meta::featured_ids()` is one indexed postmeta lookup (`meta_key`, `meta_value = '1'`, joined to posts for the type), cached in the `bfrc` object-cache group and flushed when a featured value changes: "Featured only" is `post__in` of that set (`[0]` when empty), "Not featured" is `post__not_in` of it, and the Featured sort is `posts_clauses` → `ORDER BY (ID IN (set)) dir, post_date DESC`, so a recipe that never had the key sorts as not featured and the sort costs the plain list plus the id lookup. The set is bounded only by how many recipes are curated (a literal `IN` list; tens of thousands of ids are fine, the one exception to "every list query is bounded"). `pre_get_posts` touches only the admin main query for `bfrc_recipe` and merges into any existing `post__in`/`post__not_in`/`tax_query`. While the plugin is disabled the taxonomies have no query var, so the dropdown values are applied as a `tax_query` here instead. Bulk actions `bfrc_feature`/`bfrc_unfeature` are offered to curators only; core verifies the bulk nonce; the handler requires `edit_others_bfrc_recipes`, primes the posts in one go, checks `edit_post` on every id, skips other post types and recipes already in that state (a recipe without the key counts as not featured), and redirects with `bfrc_featured_changed=N` (N = recipes whose value changed; a stale value is replaced; the argument is registered in `removable_query_args`) which `admin_notices` reports on `edit-bfrc_recipe` only. |
| Writer profile | `Admin\WriterProfile` on `show_user_profile`/`edit_user_profile` and `personal_options_update`/`edit_user_profile_update` | Rendered only for users with `edit_bfrc_recipes` (`has_profile()`; `has_profile()`, `is_listed()`, `FIELDS` and `sanitize_link()` are the shared writer rules the A4 front end reuses, so the front end depends on this Admin-namespace class by design); the image pickers only when the **viewer** has `upload_files`, and `wp_enqueue_media()` (the whole media stack plus a scan of the library's months) is loaded only on `profile.php`/`user-edit.php` for such a viewer looking at a user who has a profile. User meta exactly as listed in `uninstall.php` (`WriterProfile::keys()`, drift-tested): `bfrc_avatar_id`, `bfrc_header_image_id` (a posted `'0'` or `''` clears; a digit string naming an image attachment the **saving** user uploaded or may `edit_post` is stored, the Media Library's own rule; anything else, an absent picker included, keeps the old value), `bfrc_about` (`wp_kses_post`, capped at 5000 characters), `bfrc_website`, `bfrc_youtube`, `bfrc_facebook`, `bfrc_instagram`, `bfrc_snapchat` (`esc_url_raw` http/https with a non-empty host, ≤ 2048 characters, else `''`; **not** `wp_http_validate_url()`, which does a DNS lookup per save and refuses private or unresolvable hosts: right for a fetch, wrong for a published link), `bfrc_listed` (stored `'1'`/`'0'`; absent or unreadable falls back to the role default via `is_listed()`: Writers yes, everyone else no). String values are `wp_slash()`ed before `update_user_meta` for the same reason as above. Save: `current_user_can( 'edit_user', $user_id )` and `has_profile( $user_id )` first, then the nonce `bfrc_writer_profile`, and nothing when the section's nonce field is absent. **Multisite note:** user meta is network-wide but attachment ids are per site, so an image chosen on one site is looked up on another site's posts table; network activation is not a tested configuration in 1.0 (README). `is_listed()` calls `get_userdata()` per user: the A4 writers listing must prime users with `WP_User_Query`, not call it in a loop. |

## Front end (milestone A4)

All public classes live in `includes/Front/` and are wired by `Plugin::boot()` on every
request (services `front.writers`, `front.renderer`, `front.templates`).

| What | Where | Notes |
|---|---|---|
| Templates and routing | `Front\Templates` | **Block themes:** two templates registered with `register_block_template()` (WordPress 6.7+), `single-bfrc_recipe` and `archive-bfrc_recipe`, each the theme's header and footer parts around one server-rendered view block (`bfrc/recipe-single`, `bfrc/recipe-archive`); the `taxonomy_`, `author_` and `archive_template_hierarchy` filters prepend `archive-bfrc_recipe` for every recipe list view, so term pages and writer pages use it too. **Classic themes:** `template_include` returns `templates/single-recipe.php` or `templates/archive-recipe.php` unless the theme ships `bluefairy-recipes/{name}.php` (`Templates::locate()`); both call the same Renderer. **Writer routes** are rewrite rules added at `top` (before the post type's rules, so `chef` never matches a recipe slug): `{archive}/chef/` → `post_type=bfrc_recipe&bfrc_writers=1`, `{archive}/chef/{nicename}/` → `post_type=bfrc_recipe&author_name={nicename}&bfrc_writer=1`, both with `/page/N/`. A writer page is therefore a core author archive of the recipe type (core paginates it); the listing query is kept to one row (`posts_per_page` 1, `no_found_rows`) because the writers come from `Writers::listed()`, not from posts. `template_redirect` 404s (`set_404()`, `status_header( 404 )`, `nocache_headers()`) a writer without a public page and the listing while `writers_listing` or the plugin is off. The main list query gets `posts_per_page` (1..100 from settings), `ignore_sticky_posts`, title-only keyword search (`s` + `search_columns => ['post_title']`, ≤ 100 characters, from `bfrc_s`) and a `tax_query` from the filter form (one slug per enabled taxonomy; `0`, arrays and the term already in the URL are skipped), then `do_action( 'bfrc_archive_query', $query )`. Assets (`assets/front/recipes.css` with print rules, `assets/front/recipes.js`: debounced auto-submit, print button) load only on recipe views, or when a block renders; `comments_open` is false for recipes; `body_class` gets `bfrc-recipes`. |
| Partials | `Front\Renderer` | Every view returns a string: `archive()` (header with the archive title or term name and the "Browse recipe writers" link, filter form, grid, pagination; or the writers listing / a writer page by query var), `filters()` (GET form to the current archive or term URL; a select per enabled taxonomy with terms, `number` 200; keyword; reset link when filtered), `grid()`/`card()` (photo or placeholder, title, prep/cook/servings badges, "By Writer" linked only when the writer has a public page, trimmed excerpt), `pagination()` (`paginate_links`), `writers_list()` (24 per page, avatar or Gravatar, name, recipe count, trimmed about; pagination on `{listing}/page/N/`), `writer_page()` (header image, avatar, about with `wpautop` + `wp_kses_post`, links with `rel="noopener nofollow me"`, "Recipes by" grid), `single()` (title, writer and date, badges with total, linked terms, hero = cookie-less video iframe or the photo, summary, body content through `the_content`, ingredients, steps, nutrition facts, share buttons, print button). **Seams:** `bfrc_ingredient_label_html( $html, $row, $recipe_id )` (the add-on links product and affiliate rows), `bfrc_render_ingredient_actions`, `bfrc_single_after_steps`, `bfrc_single_after_nutrition`, `bfrc_single_footer` (each receives the recipe id; output captured). **No per-card queries:** `writer_links()` primes the authors with `cache_users()` and asks `count_many_users_posts()` once per grid; `RecipeData` reads one `get_post_meta( $id )` (primed by the list query); writer images are primed with `_prime_post_caches()`. |
| Recipe fields | `Front\RecipeData` | One object per recipe read through the Content\Meta sanitisers (`for_post()`), with `format_minutes()` ("45 min", "1 hr 15 min"), `servings_text()` ("2–4 servings"), `has_video()`/`embed_url()` (`youtube-nocookie.com`, `player.vimeo.com`, never fetched server-side), `nutrition_present()`. The JSON-LD (A6) and the REST item (A5) read the same object. |
| Writer rules | `Front\Writers` | A public page exists only for a user who is listed (`WriterProfile::is_listed()`: the Writer role unless opted out, anyone else by opting in) AND has a published recipe (`is_public()`, `public_writer( $nicename )`). `listed( $page )`: two bounded `WP_User_Query`s (`role__in` Writer with the listed meta absent or not `'0'`; anyone with `'1'`; `number` 500 each), merged by id, one `count_many_users_posts()`, zero counts dropped, display-name order, 24 per page. URLs: `listing_url()`, `url( $user )` under `{archive}/chef/`. `profile( $user_id )` reads the display fields; `instance()` returns the service. |
| Share buttons | `Renderer::share_buttons()` | Facebook sharer, X intent (URL + title), Pinterest create-pin with `media` = the recipe photo's full URL; each gated by its setting and the master switch; `target="_blank" rel="noopener nofollow"`. |
| Query ceilings | §8 of the plan | Archive: the main `WP_Query` + `get_terms` per enabled taxonomy with terms (core-cached) + `cache_users` + one count. Detail: one meta read, one count. Writers listing: two user queries + one count + one attachment prime. Asserted on the rig in Phase C. |

## Activation, deactivation, uninstall

- **Activation** (`Installer::provision_site()`): roles, `sanitize( DEFAULTS )` when no
  settings exist, core-role grants per the setting, versions, flush flag. Idempotent.
  Network activation walks sites in pages of 200; it is handled but not a tested
  configuration in 1.0 (README says so).
- **Deactivation**: lowers the flag and deletes the stored `rewrite_rules`, so core rebuilds
  them on the next request without this plugin's (a flush during deactivation would write
  them back, the post type being still registered). Roles, settings, versions and content
  stay.
- **Uninstall** (`uninstall.php`, standalone): reads every site's choice first, removes the
  writer profiles next (when every site opted in), then cleans each site with its options
  last, so an interrupted run can be repeated. Always removes the listed options, every
  other `bfrc_…` option (core's taxonomy `_children` caches, dynamic transients), the stored
  rewrite rules, the two roles (users keep a role: `subscriber` when it was their only one;
  a pass that makes no progress ends the loop), the recipe capabilities on core roles, and
  the `bfrc` cache group. Only a stored boolean `true` in `delete_data_on_uninstall` opts a
  site in to losing content: recipes (keyset batches of 100, force-deleted through core's
  own routine with cache invalidation suspended and one flush at the end), the terms of
  every `bfrc_*` taxonomy with their meta (only those terms, and only where no other
  taxonomy still uses them), and the writer profile user meta. User meta is shared by a
  whole network, so profiles are removed only when every site opted in (a site that never
  ran the plugin has no settings and counts as "no"). The term loop stops when a batch could
  not be deleted; the recipe loop pages by id. After a reinstall recipes and profiles are
  intact; writers must be given the Recipe Writer role again.
- **Known limit:** a database that refuses the `bfrc_db_version` write makes every request
  re-run `provision_site()` (role caps re-asserted, three option writes attempted); the
  rewrite flush is not repeated. Such a site is broken in ways this plugin cannot fix.

## Extension seams (this list is mirrored in FEATURES.md "Extending")

| Seam | Kind | Since | Purpose |
|---|---|---|---|
| `bfrc_loaded` | action | 1.0.0 | fired before the base binds anything |
| `bfrc_booted` | action | 1.0.0 | fired after every subsystem is wired |
| `bfrc_is_pro` | filter (bool) | 1.0.0 | Pro answers true after `base_ok()`; hides the upsell |
| `bfrc_filter_taxonomies` | filter (array) | 1.0.0 | add filter types keyed by code (`label`, `singular`, `sort_order`); system codes cannot be changed |
| `bfrc_ingredient_types` | filter (list) | 1.0.0 | add ingredient row types beyond `plain` |
| `bfrc_ingredient_row_fields` | filter (array, $type) | 1.0.0 | extra row fields for a type, each a sanitiser callable |
| `bfrc_reserved_slugs` | filter (list) | 1.0.0 | more recipe slugs to refuse |
| `bfrc_recipe_meta_boxes` | action ($post) | 1.0.0 | fired after the five base boxes are registered on the recipe edit screen; add yours |
| `bfrc_ingredient_type_labels` | filter (array, $types) | 1.0.0 | human labels for ingredient row types (fallback `ucfirst`) |
| `bfrc_ingredient_row_field_labels` | filter (array, $type) | 1.0.0 | labels for a type's extra row fields (fallback: the field name) |
| `bfrc_archive_query` | action ($query) | 1.0.0 | the public recipe list query, after per-page, search and filters are set |
| `bfrc_ingredient_label_html` | filter ($html, $row, $recipe_id) | 1.0.0 | the escaped label markup of one ingredient row on the recipe page |
| `bfrc_render_ingredient_actions` | action ($recipe_id) | 1.0.0 | after the ingredient list (the add-on's cart and affiliate buttons) |
| `bfrc_single_after_steps` | action ($recipe_id) | 1.0.0 | after the method |
| `bfrc_single_after_nutrition` | action ($recipe_id) | 1.0.0 | after the nutrition facts |
| `bfrc_single_footer` | action ($recipe_id) | 1.0.0 | end of the recipe (the add-on's reviews) |
| `bfrc_recipe_saved` | action ($post_id, $clean, $raw) | 1.0.0 | the recipe edit form was saved; `$clean` = meta key => value written, after capability and nonce |

Seams added by later milestones (
`bfrc_schema_data`, `bfrc_settings_tabs`, `bfrc_settings_page_footer`,
`bfrc_settings_saved`, `bfrc_admin_menu_items`,
`bfrc_rest_recipe_item`) are appended to this table as they land.

## Tests

`tests/bootstrap.php` is a WordPress stub harness (no database, no WordPress): hooks really
dispatch, options and transients live in arrays, roles and users resolve capabilities as
core does, `$wpdb` is `tests/FakeWpdb.php` with a scripted answer queue. Module stubs go in
`tests/stubs/<module>.php`. Every public and protected method is named in a test under
`tests/Unit/` (`php bin/method-coverage.php`, self-checked with `--self-check`).
`uninstall.php` is executed for real by `UninstallTest`; the entry file by `EntryFileTest`
in a separate PHP process.
