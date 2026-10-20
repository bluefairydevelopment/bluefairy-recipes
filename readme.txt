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

In development. This file is completed before the first release.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from Plugins → Add New.
2. Activate it.
3. Open Recipes → Settings. The Data tab can install three sample recipes to look at, and remove them again.

== External services ==

This plugin embeds recipe videos from two services when a recipe's writer sets a video URL. Nothing else contacts another server, and no data is sent anywhere by the plugin itself.

= YouTube =
What: the YouTube player (an iframe from youtube-nocookie.com, the privacy-enhanced mode, which sets no cookies until the visitor plays the video).
When: a visitor opens a recipe whose video is a YouTube video. The visitor's browser then loads the player from YouTube, which receives the visitor's IP address and browser details as with any embedded video.
Terms: https://www.youtube.com/t/terms — Privacy: https://policies.google.com/privacy

= Vimeo =
What: the Vimeo player (an iframe from player.vimeo.com).
When: a visitor opens a recipe whose video is a Vimeo video. The visitor's browser then loads the player from Vimeo, which receives the visitor's IP address and browser details.
Terms: https://vimeo.com/terms — Privacy: https://vimeo.com/privacy

The plugin never fetches a video, a thumbnail or any data from these services on the server.

== Development ==

The blocks' JavaScript is built with @wordpress/scripts from the `src/` directory shipped with the plugin (`npm install && npm run build`). Source, tests and the full history: https://github.com/bluefairydevelopment/bluefairy-recipes

== Changelog ==

= 1.0.0 =
* Initial release.
