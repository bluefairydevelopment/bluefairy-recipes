=== BlueFairy Recipes ===
Contributors: bluefairydevelopment
Tags: recipes, recipe, food blog, schema, nutrition
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Recipes for WordPress: ingredients, steps, timings, nutrition, writer profiles, browse filters and Recipe JSON-LD.

== Description ==

BlueFairy Recipes turns WordPress into a recipe site. Each recipe has ingredients with measures, numbered steps, prep and cook times, servings, nutrition facts, a photo or a YouTube or Vimeo video, and a writer with a public profile page. Visitors browse by meal type, diet, season and cuisine, search by title, and share or print a recipe. Google gets Recipe structured data on every recipe page with a photo.

= On the site =

* A recipe archive with a filter panel (one dropdown per filter type that has terms, a title search, Filter and Reset), card grid and pagination; a changed filter applies itself after a moment, and the form works without JavaScript.
* A page for every filter term (`/recipes/diet/vegan/`) and a writers listing with a page and feed per writer.
* Recipe pages with the photo or video, timings, servings, filter tags, ingredients, method, nutrition facts per serving, share buttons (Facebook, X, Pinterest) and a print layout.
* Recipe JSON-LD for Google's recipe rich results, switchable in Settings.
* Block themes: Single Recipe, Recipes Archive and Recipe Writers templates you can edit in the Site Editor. Classic themes: bundled templates you can override from your theme.
* Blocks: Recipe List (with fixed filters, per page, filter form and pagination switches; its filters update the list without a reload), Recipe Filters, Featured Recipes and Recipe Card, plus the shortcodes `[bfrc_recipes]`, `[bfrc_featured]` and `[bfrc_writers]`.
* A public read-only REST endpoint, `GET /wp-json/bfrc/v1/recipes`, for themes and apps.

= In the admin =

* A recipe edit screen with Recipe Details, Recipe Video, Ingredients, Steps and Nutrition panels; rows can be added, moved and dragged; works in the block editor and the classic editor.
* A Recipes list with photo, time, servings and featured columns, filters by filter type and featured, and bulk Mark/Unmark featured.
* Two roles: Recipe Writer (own recipes) and Recipe Manager (every recipe, the filter types, the settings). Authors and Editors get recipe capabilities too while "Grant core roles" is on.
* Writer profile fields on the user profile: photo, header image, about text, website and social links, and a switch to appear on the public writers page.
* Recipes → Settings: on/off switch, rich snippets, archive slug and title, recipes per page, share buttons, writer listing, core role grants, filter types on/off and order, uninstall data choice, and three sample recipes to install and remove.

= BlueFairy Recipes Pro =

The Pro add-on adds visitor reviews with star ratings, moderation and a rating in Google results; recipe submissions from visitors with photos, approval and admin notes; WooCommerce shoppable ingredients, affiliate ingredient links and related recipes on product pages; and your own filter types beyond meal type, diet, season and cuisine.

= Requirements =

WordPress 6.7 or newer with pretty permalinks, PHP 8.1 or newer. 1.0 is a single-site product: on a multisite network, activate it on each site; network activation is not supported.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from Plugins → Add New Plugin.
2. Activate it.
3. Open Recipes → Settings. The Data tab can install three sample recipes to look at, and remove them again.
4. Add a recipe under Recipes → Add New. It appears at /recipes/ (the archive slug is a setting) and on its filter pages; its writer gets a public page once they are listed (Recipe Writers are, by default) and have a published recipe.

== Frequently Asked Questions ==

= Does it work with my theme? =

Yes. A block theme gets three templates (Single Recipe, Recipes Archive, Recipe Writers) to arrange in the Site Editor. A classic theme uses the plugin's templates, which you can override by copying them into a `bluefairy-recipes` folder in your theme.

= How do I put the recipe archive in my menu? =

Add a custom link to `/recipes/` (or your archive slug) in a Navigation block. A theme with a menu screen also lists a "Recipe Archives" item under Appearance → Menus → Recipes once a recipe is published. The plugin does not add menu items itself.

= Where is the structured data? =

Every recipe page with a featured image carries a Recipe JSON-LD script for Google, with the ingredients, steps, times, servings, nutrition, categories and, for a video recipe, the video. Turn it off under Recipes → Settings → General if your SEO plugin prints its own.

= Can visitors filter recipes without reloading the page? =

In a Recipe List block (or the `[bfrc_recipes]` shortcode), yes: the list updates after a short pause when a filter or the search box changes, and the page address follows so a reload or a shared link opens the same list. The recipe archive page itself applies a changed filter by reloading. Without JavaScript the forms and the page links still work.

= What happens to my recipes when I uninstall the plugin? =

They stay, with their fields, terms and writer profiles, unless you turned on "Delete all data on uninstall" under Recipes → Settings → Data. The plugin's roles are removed either way, so after a reinstall give your writers the Recipe Writer role again.

= Does it support multisite? =

Each site on a network can activate the plugin on its own. Network activation is not supported in 1.0.

= Are the sample recipes real? =

They are three invented recipes with placeholder images, meant to show you the pages. Install and remove them from Recipes → Settings → Data; removing deletes them permanently, even if you edited or published them.

== External services ==

This plugin embeds recipe videos from two services when a recipe's writer sets a video URL, and shows a writer's profile picture through WordPress's own avatar function when the writer has not chosen a profile photo. Nothing else contacts another server, and no data is sent anywhere by the plugin itself.

= YouTube =

What: the YouTube player (an iframe from youtube-nocookie.com, the privacy-enhanced mode: YouTube states it stores no viewing information before the visitor plays the video).

When: a visitor opens a recipe whose video is a YouTube video. The visitor's browser then loads the player from YouTube, which receives the visitor's IP address and browser details as with any embedded video.

Terms: https://www.youtube.com/t/terms — Privacy: https://policies.google.com/privacy

= Vimeo =

What: the Vimeo player (an iframe from player.vimeo.com).

When: a visitor opens a recipe whose video is a Vimeo video. The visitor's browser then loads the player from Vimeo, which receives the visitor's IP address and browser details.

Terms: https://vimeo.com/terms — Privacy: https://vimeo.com/privacy

The plugin never fetches a video, a thumbnail or any data from these services on the server.

= Gravatar =

What: a writer's picture on the writer pages and the writers listing, through WordPress's own avatar function (get_avatar), which loads it from gravatar.com, but only for a writer who has not chosen a profile photo and only while the site's Discussion settings show avatars.

When: a visitor opens a writer page or the writers listing, the writer has no profile photo, and avatars are enabled in Settings → Discussion. The visitor's browser requests the picture from Gravatar, which receives the visitor's IP address and a hash of the writer's email address. Choosing a profile photo for the writer, or turning avatars off in Settings → Discussion, stops this.

Terms: https://gravatar.com/site/terms-of-service/ — Privacy: https://automattic.com/privacy/

= Share buttons =

The Facebook, X and Pinterest share buttons on a recipe page are plain links to those services' share pages; nothing loads from them until a visitor clicks one. Each can be switched off under Recipes → Settings → Listing.

== Development ==

The blocks' JavaScript is built with @wordpress/scripts from the `src/` directory shipped with the plugin (`npm install && npm run build`). Source, tests and the full history: https://github.com/bluefairydevelopment/bluefairy-recipes (public from the first release).

== Changelog ==

= 1.0.0 =
* Initial release: recipes with ingredients, steps, timings, servings, nutrition, photo or video; four filter types; archive with filters, search and pagination; writer profiles and pages; Recipe JSON-LD; share and print; blocks, shortcodes and the public REST endpoint; the settings screen, two roles and the sample recipes.

== Upgrade Notice ==

= 1.0.0 =
First release.
