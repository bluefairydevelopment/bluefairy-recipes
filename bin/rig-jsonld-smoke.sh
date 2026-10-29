# Recipe JSON-LD beside SEO plugins' own structured data (rig only; never shipped). Builds
# WordPress 7.1 at ~/g236wp, database g236smoke, php -S 127.0.0.1:8099, publishes a recipe
# with a photo and a plain post (control), and reads every <script type="application/ld+json">
# on the recipe page with no SEO plugin, BlueFairy SEO Toolkit (+ Pro), Yoast SEO, Rank Math
# and All in One SEO. The recipe page must carry exactly one Recipe node (ours, with name and
# image), and the SEO plugin's own graph must not describe the recipe as a second main
# entity (Article/BlogPosting/NewsArticle). Prints every @type it saw per state.
#   SRC=<plugin dir>   the Recipes tree to install
#   ZIPS=<dir>         holds bluefairy-seo-toolkit-1.1.2.zip and bluefairy-seo-toolkit-pro-1.2.0.zip
#   MUTATE=dup         positive control: a must-use plugin prints a second Recipe (every state must FAIL)
# Exit status: 1 when any check fails, else 0.
set -u
SRC=${SRC:-~/g232/bluefairy-recipes}; ZIPS=${ZIPS:-~/g234/zips}
W=~/g236wp; PORT=8099; U="http://127.0.0.1:$PORT"
pw=$(grep DB_PASSWORD ~/Sites/wp-testing/wp-config.php | sed -E "s/.*'DB_PASSWORD', '([^']*)'.*/\1/")
mysql -u wptest -p"$pw" -h 127.0.0.1 -e "DROP DATABASE IF EXISTS g236smoke; CREATE DATABASE g236smoke" </dev/null 2>/dev/null || { echo "DB CREATE FAILED"; exit 1; }
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null; rm -rf "$W"; mkdir -p "$W"; cd "$W" || exit 2
wp core download --version=7.1 --quiet </dev/null || exit 2
printf "define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );\n" > /tmp/g236-extra.php
wp config create --dbname=g236smoke --dbuser=wptest --dbpass="$pw" --dbhost=127.0.0.1 --quiet --extra-php < /tmp/g236-extra.php
wp core install --url="$U" --title="Kitchen" --admin_user=admin --admin_password=c-admin --admin_email=owner@example.test --skip-email --quiet </dev/null
wp rewrite structure '/%postname%/' --quiet </dev/null
cp -R "$SRC" wp-content/plugins/bluefairy-recipes; rm -rf wp-content/plugins/bluefairy-recipes/{node_modules,tests,vendor}
for z in "$ZIPS"/bluefairy-seo-toolkit-1.1.2.zip "$ZIPS"/bluefairy-seo-toolkit-pro-1.2.0.zip; do wp plugin install "$z" --quiet </dev/null || echo "INSTALL FAILED $z"; done
wp plugin install wordpress-seo --quiet </dev/null || echo "YOAST INSTALL FAILED"
wp plugin install seo-by-rank-math --quiet </dev/null || echo "RANK MATH INSTALL FAILED"
wp plugin install all-in-one-seo-pack --quiet </dev/null || echo "AIOSEO INSTALL FAILED"
echo "versions: $(wp plugin list --fields=name,version --format=csv </dev/null | tr '\n' ' ')"
wp plugin activate bluefairy-recipes --quiet </dev/null
wp theme activate twentytwentyfive --quiet </dev/null 2>/dev/null
php -r '$i = imagecreatetruecolor( 1200, 800 ); imagefill( $i, 0, 0, imagecolorallocate( $i, 200, 80, 40 ) ); imagejpeg( $i, "/tmp/g236-soup.jpg" );'
RECIPE=$(wp eval '
$id = wp_insert_post( [ "post_type" => "bfrc_recipe", "post_status" => "publish", "post_title" => "Tomato Soup", "post_name" => "tomato-soup", "post_excerpt" => "A warm soup.", "post_author" => 1 ] );
update_post_meta( $id, "_bfrc_ingredients", [ [ "type" => "text", "quantity" => "4", "unit" => "", "label" => "tomatoes" ] ] );
update_post_meta( $id, "_bfrc_steps", [ [ "text" => "Chop." ] ] );
echo $id;' </dev/null)
wp media import /tmp/g236-soup.jpg --post_id="$RECIPE" --featured_image --quiet </dev/null
wp eval 'wp_insert_post( [ "post_type" => "post", "post_status" => "publish", "post_title" => "Control post", "post_name" => "control-post" ] );' </dev/null
if [ "${MUTATE:-}" = dup ]; then
	mkdir -p wp-content/mu-plugins
	printf '%s\n' '<?php add_action( "wp_head", static function () { if ( is_singular( "bfrc_recipe" ) ) { echo "<script type=\"application/ld+json\">{\"@context\":\"https://schema.org\",\"@type\":\"Recipe\",\"name\":\"dup\"}</script>"; } } );' > wp-content/mu-plugins/dup-recipe.php
	echo "mutated: a second Recipe node"
fi
wp rewrite flush --quiet </dev/null
PHP_CLI_SERVER_WORKERS=4 setsid nohup php -S 127.0.0.1:$PORT -t "$W" >/tmp/g236-server.log 2>&1 </dev/null & disown
sleep 2
URL=$(wp eval "echo get_permalink( $RECIPE );" </dev/null)
echo "recipe=$URL"
pass=0; fail=0
ok(){ pass=$((pass+1)); echo "PASS [$STATE] $1"; }
no(){ fail=$((fail+1)); echo "FAIL [$STATE] $1"; }
probe(){
	local code
	code=$(curl -s -o /tmp/g236-body.html -w '%{http_code}' "$URL")
	[ "$code" = 200 ] || { no "recipe page: $code"; return; }
	# Every JSON-LD block, every node (top level, arrays and @graph), its @type list.
	php -r '
		$html = file_get_contents( "/tmp/g236-body.html" );
		preg_match_all( "#<script[^>]*type=[\"\x27]application/ld\+json[\"\x27][^>]*>(.*?)</script>#s", $html, $m );
		$types = []; $recipes = []; $bad = 0;
		$walk = function ( $node ) use ( &$walk, &$types, &$recipes ) {
			if ( ! is_array( $node ) ) { return; }
			if ( isset( $node["@type"] ) ) {
				foreach ( (array) $node["@type"] as $t ) { $types[] = $t; if ( "Recipe" === $t ) { $recipes[] = $node; } }
			}
			foreach ( $node as $k => $v ) { if ( is_array( $v ) && "@type" !== $k ) { $walk( $v ); } }
		};
		foreach ( $m[1] as $json ) { $d = json_decode( $json, true ); if ( null === $d ) { $bad++; continue; } $walk( $d ); }
		$main = array_values( array_intersect( $types, [ "Article", "BlogPosting", "NewsArticle" ] ) );
		echo count( $m[1] ), " ", $bad, " ", count( $recipes ), " ", ( isset( $recipes[0]["name"], $recipes[0]["image"] ) ? 1 : 0 ), " ", count( $main ), " ", implode( ",", array_count_values( $types ) ? array_map( fn( $t, $n ) => "$t:$n", array_keys( array_count_values( $types ) ), array_count_values( $types ) ) : [] ), "\n";
	' > /tmp/g236-probe.txt
	read -r blocks bad recipes complete mains types < /tmp/g236-probe.txt
	echo "TYPES [$STATE] blocks=$blocks $types"
	[ "$bad" = 0 ] && ok "every JSON-LD block parses" || no "$bad JSON-LD block(s) do not parse"
	[ "$recipes" = 1 ] && ok "exactly one Recipe" || no "$recipes Recipe nodes (want 1)"
	[ "$complete" = 1 ] && ok "the Recipe has name and image" || no "the Recipe lacks name or image"
	[ "$mains" = 0 ] && ok "no Article-type node describes the recipe too" || no "$mains Article-type node(s) beside the Recipe"
}
STATE="no SEO plugin"; probe
STATE="SEO Toolkit"; wp plugin activate bluefairy-seo-toolkit --quiet </dev/null; probe
STATE="SEO Toolkit + Pro"; wp plugin activate bluefairy-seo-toolkit-pro --quiet </dev/null; probe
wp plugin deactivate bluefairy-seo-toolkit-pro bluefairy-seo-toolkit --quiet </dev/null
STATE="Yoast defaults"; wp plugin activate wordpress-seo --quiet </dev/null && probe
wp plugin deactivate wordpress-seo --quiet </dev/null
wp option update rank_math_wizard_completed 1 --quiet </dev/null; wp option update rank_math_registration_skip 1 --quiet </dev/null
STATE="Rank Math defaults"; wp plugin activate seo-by-rank-math --quiet </dev/null && probe
wp plugin deactivate seo-by-rank-math --quiet </dev/null
STATE="All in One SEO defaults"; wp plugin activate all-in-one-seo-pack --quiet </dev/null && probe
grep -E "PHP (Fatal|Warning|Notice|Deprecated)" "$W/wp-content/debug.log" 2>/dev/null | grep -i "recipes" | head -5 | sed 's/^/debug.log: /'
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null
mysql -u wptest -p"$pw" -h 127.0.0.1 -e "DROP DATABASE IF EXISTS g236smoke" </dev/null 2>/dev/null; rm -rf "$W"
echo "JSONLD pass=$pass fail=$fail"
exit $(( fail > 0 ? 1 : 0 ))
