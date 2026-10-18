# BlueFairy Recipes — Features Guide

**In development: 1.0.0 is not released.** Sections are added as each milestone lands and
every claim is verified against the code before the release.

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
| Servings | | max is never below min: whichever you change last, the other follows |

### Editing a recipe

The recipe fields are edited in five panels on the recipe screen, in both the block editor and the classic editor: **Recipe Details** (times, servings, Featured), **Recipe Video** (photo or video, and the video URL), **Ingredients**, **Steps** and **Nutrition**. Ingredient and step rows can be added, removed, moved with the arrow buttons or dragged by their handle into order; a recipe can hold up to 200 of each, and a new recipe starts with one blank row so it can be filled even without JavaScript. Servings are checked together: raising the minimum above the maximum raises the maximum, lowering the maximum below the minimum lowers the minimum. A video URL that is not a YouTube or Vimeo video is not saved: the block editor shows a warning under the field as soon as the save finishes, the classic editor shows it after the page reloads, and either way the warning names the URL that was refused. Fields are saved only by the recipe form itself: a quick edit, an autosave or a request that did not post the panels never clears them, and a panel that did not reach the server in full keeps its previous values. **Featured** is a curation flag: Recipe Managers, Editors and Administrators set it (in the panel, by bulk action, or through the REST API); writers see it read-only and their saves never change it.

Very long recipes: a web server accepts a limited number of form fields per request (PHP's `max_input_vars`, usually 1000). A recipe of 200 ingredients and 200 steps fits within that default; the editor warns before the form outgrows the limit, and a panel that was cut off keeps its previous values instead of being emptied.

### The Recipes list

The list shows the photo, prep and cook time, servings and a featured star for each recipe, plus a column for every enabled filter type. Above the list you can filter by any filter type that has terms and by featured or not, and sort by the Featured column (recipes that were never marked sort as not featured). Curators get two bulk actions, **Mark featured** and **Unmark featured**, which apply to the recipes they may edit and report how many recipes actually changed.

### Writer profiles

Every user who can edit recipes has a **Recipe writer profile** section on their profile screen: a profile photo and a header image from the media library (shown to users who may upload files), an About paragraph (basic formatting kept, up to 5000 characters), a website and YouTube, Facebook, Instagram and Snapchat links (http or https with a host; anything else is cleared), and **List this writer on the public writers page**, which is on by default for the Recipe Writer role and off for everyone else. Only an image you uploaded yourself, or may edit, is accepted as a photo; any other choice keeps the previous image. Only someone who may edit that user can save the section.

## Filter types (taxonomies)

Recipes are browsed by four filter types, each a taxonomy under the recipe archive:

| Filter type | Taxonomy | URL | Starter terms (seeded once, on activation, only into an empty taxonomy) |
|---|---|---|---|
| Meal Types | `bfrc_meal_type` | `/recipes/meal-type/…` | Breakfast, Lunch, Dinner, Dessert, Snack |
| Diets | `bfrc_diet` | `/recipes/diet/…` | Vegetarian, Vegan, Gluten-Free, Dairy-Free |
| Seasons | `bfrc_season` | `/recipes/season/…` | Spring, Summer, Autumn, Winter |
| Cuisines | `bfrc_cuisine` | `/recipes/cuisine/…` | Italian, Mexican, Asian, American, Mediterranean |

Each can be switched off and reordered in Settings. A switched-off filter type keeps its terms and its admin screen but has no public archive and no public REST route. The Recipe Manager role manages terms; writers assign them. A filter type's name may not be a route under the archive (`chef`, `page`, a feed name).

## Settings

Stored in one option, `bfrc_settings`. The settings screen arrives with a later milestone;
until then the values below are the defaults the plugin installs.

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

## Roles

| Role | Can |
|---|---|
| Recipe Writer (`bfrc_writer`) | create, edit, publish and delete their own recipes; assign terms; upload files |
| Recipe Manager (`bfrc_recipe_manager`) | everything a writer can, for every recipe; manage the recipe taxonomies; change settings |

Administrators can always change settings.

## Extending

| Hook | Kind | Purpose |
|---|---|---|
| `bfrc_loaded` | action | Fired before the plugin binds anything; the Pro add-on attaches its filters here |
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

## Differences from the Magento extension

Written with the content model and front end (later milestones).
