# Canonical count check (rig only; never shipped). Builds its own WordPress 7.1 at ~/g234wp,
# database g234smoke, php -S 127.0.0.1:8097, and counts <link rel=canonical> tags on the
# writer pages, the writers listing, a recipe, the archive (plain, filtered, keyword) and a
# term page with no SEO plugin, BlueFairy SEO Toolkit, SEO Toolkit + Pro (canonical on and
# off), Yoast with the toolkit, Yoast alone and Rank Math alone. Every view must carry at
# most one, and the writer views exactly one naming themselves.
#   SRC=<plugin dir>   the Recipes tree to install (default ~/g234/bluefairy-recipes)
#   ZIPS=<dir>         holds bluefairy-seo-toolkit-1.1.2.zip and bluefairy-seo-toolkit-pro-1.2.0.zip
# Exit status: 1 when any check fails, else 0.
set -u
SRC=${SRC:-~/g234/bluefairy-recipes}; ZIPS=${ZIPS:-~/g234/zips}
W=~/g234wp; PORT=${PORT:-8097}; U="http://127.0.0.1:$PORT"
pw=$(grep DB_PASSWORD ~/Sites/wp-testing/wp-config.php | sed -E "s/.*'DB_PASSWORD', '([^']*)'.*/\1/")
mysql -u wptest -p"$pw" -h 127.0.0.1 -e "DROP DATABASE IF EXISTS g234smoke; CREATE DATABASE g234smoke" </dev/null 2>/dev/null || { echo "DB CREATE FAILED"; exit 1; }
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null; rm -rf "$W"; mkdir -p "$W"; cd "$W"
sleep 1; if ss -ltn 2>/dev/null | grep -qE "127\.0\.0\.1:$PORT\b"; then echo "PORT $PORT IN USE by another process (another session's server?): set PORT=<free port>"; exit 2; fi # A request would reach that server, not ours.
wp core download --version=7.1 --quiet </dev/null || exit 2
printf "define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );\n" > /tmp/g234-extra.php
wp config create --dbname=g234smoke --dbuser=wptest --dbpass="$pw" --dbhost=127.0.0.1 --quiet --extra-php < /tmp/g234-extra.php
grep -q "WP_DEBUG_LOG', true" wp-config.php || { echo "EXTRA PHP LOST"; exit 2; }
wp core install --url="$U" --title="C" --admin_user=admin --admin_password=c-admin --admin_email=owner@example.test --skip-email --quiet </dev/null
wp rewrite structure '/%postname%/' --quiet </dev/null
cp -R "$SRC" wp-content/plugins/bluefairy-recipes; rm -rf wp-content/plugins/bluefairy-recipes/{node_modules,tests,vendor}
echo "recipes source: $SRC ($(grep -c seo_toolkit_prints_canonical wp-content/plugins/bluefairy-recipes/includes/Front/Templates.php) toolkit refs)"
wp plugin install "$ZIPS"/bluefairy-seo-toolkit-1.1.2.zip --quiet </dev/null
wp plugin install "$ZIPS"/bluefairy-seo-toolkit-pro-1.2.0.zip --quiet </dev/null
wp plugin install wordpress-seo --quiet </dev/null || echo "YOAST INSTALL FAILED"
wp plugin install seo-by-rank-math --quiet </dev/null || echo "RANK MATH INSTALL FAILED"
wp plugin activate bluefairy-recipes --quiet </dev/null
wp theme activate twentytwentyfive --quiet </dev/null 2>/dev/null
wp eval '
$w = wp_insert_user( [ "user_login" => "ann", "user_pass" => wp_generate_password(), "user_email" => "ann@example.test", "display_name" => "Ann", "role" => "bfrc_writer" ] );
$diet = term_exists( "Vegetarian", "bfrc_diet" ) ?: wp_insert_term( "Vegetarian", "bfrc_diet" );
for ( $i = 1; $i <= 3; $i++ ) {
	$r = wp_insert_post( [ "post_type" => "bfrc_recipe", "post_status" => "publish", "post_title" => "Soup $i", "post_name" => "soup-$i", "post_author" => $w ] );
	wp_set_object_terms( $r, (int) $diet["term_id"], "bfrc_diet" );
}' </dev/null
wp rewrite flush --quiet </dev/null
PHP_CLI_SERVER_WORKERS=4 setsid nohup php -S 127.0.0.1:$PORT -t "$W" >/tmp/g234-server.log 2>&1 </dev/null & disown
sleep 2
ARCH=$(wp eval 'echo get_post_type_archive_link("bfrc_recipe");' </dev/null)
REC=$(wp eval 'echo get_permalink( get_page_by_path( "soup-1", OBJECT, "bfrc_recipe" ) );' </dev/null)
TERM=$(wp eval 'echo get_term_link( "vegetarian", "bfrc_diet" );' </dev/null)
LIST="${ARCH}chef/"; WRITER="${ARCH}chef/ann/"
echo "archive=$ARCH recipe=$REC term=$TERM writer=$WRITER"
pass=0; fail=0
check(){ # url expected-count expected-href(optional) label
	local html n hrefs
	html=$(curl -s "$1")
	n=$(grep -oE "<link[^>]*rel=[\"']canonical[\"'][^>]*>" <<<"$html" | wc -l | tr -d ' ')
	hrefs=$(grep -oE "<link[^>]*rel=[\"']canonical[\"'][^>]*>" <<<"$html" | grep -oE "href=[\"'][^\"']*" | cut -c7- | paste -sd' ')
	if { [ "$n" = "$2" ] || { [ "$2" = le1 ] && [ "$n" -le 1 ]; }; } && { [ -z "${3:-}" ] || [ "$hrefs" = "$3" ]; }; then pass=$((pass+1)); echo "PASS [$STATE] $4: $n canonical ${hrefs}"; else fail=$((fail+1)); echo "FAIL [$STATE] $4: got $n '$hrefs', want $2 '${3:-any}'"; fi
}
ogurl(){ curl -s "$1" | grep -oE "<meta[^>]*property=[\"']og:url[\"'][^>]*>" | grep -oE "content=[\"'][^\"']*" | cut -c10- | paste -sd' '; }
views(){ # expected counts for archive/term/filtered archive (0 without a plugin printing archive canonicals, else 1)
	if [ "${2:-}" = noindex ]; then # Rank Math noindexes author archives by default and then prints no canonical: require that pairing
		if curl -s "$WRITER" | grep -qE 'name="robots" content="[^"]*noindex'; then check "$WRITER" 0 "" "writer page (noindexed by the SEO plugin)"; else check "$WRITER" 1 "$WRITER" "writer page"; fi
	else check "$WRITER" 1 "$WRITER" "writer page"; fi
	check "$LIST" 1 "$LIST" "writers listing"
	check "${WRITER}?s=x" le1 "" "writer page with ?s= (a search: an SEO plugin may print none)"
	check "$REC" 1 "$REC" "single recipe"
	check "$ARCH" "$1" "" "archive"
	check "${ARCH}?bfrc_filter%5Bdiet%5D=vegetarian" "$1" "" "archive filtered"
	check "${ARCH}?bfrc_s=soup" "$1" "" "archive keyword"
	check "$TERM" "$1" "" "term page"
}
STATE="no SEO plugin"; views 0
STATE="SEO Toolkit 1.1.2"; wp plugin activate bluefairy-seo-toolkit --quiet </dev/null; views 0
o=$(ogurl "$WRITER"); if [ "$o" = "$WRITER" ]; then pass=$((pass+1)); echo "PASS [$STATE] writer og:url $o"; else fail=$((fail+1)); echo "FAIL [$STATE] writer og:url '$o' want $WRITER"; fi
STATE="SEO Toolkit 1.1.2 + Pro 1.2.0"; wp plugin activate bluefairy-seo-toolkit-pro --quiet </dev/null; views 1
o=$(ogurl "$WRITER"); if [ "$o" = "$WRITER" ]; then pass=$((pass+1)); echo "PASS [$STATE] writer og:url $o"; else fail=$((fail+1)); echo "FAIL [$STATE] writer og:url '$o' want $WRITER"; fi
STATE="Pro canonical switched off"; wp eval '$f = get_option( "bfst_features", [] ); $f["canonical"] = false; update_option( "bfst_features", $f );' </dev/null; views 0
wp eval '$f = get_option( "bfst_features", [] ); $f["canonical"] = true; update_option( "bfst_features", $f );' </dev/null
STATE="Yoast + SEO Toolkit + Pro"; wp plugin activate wordpress-seo --quiet </dev/null && views 1
wp plugin deactivate wordpress-seo bluefairy-seo-toolkit-pro bluefairy-seo-toolkit --quiet </dev/null
STATE="Yoast alone"; wp plugin activate wordpress-seo --quiet </dev/null && views 1
wp plugin deactivate wordpress-seo --quiet </dev/null
wp option update rank_math_wizard_completed 1 --quiet </dev/null; wp option update rank_math_registration_skip 1 --quiet </dev/null # Rank Math prints no head before its setup wizard
STATE="Rank Math alone"; wp plugin activate seo-by-rank-math --quiet </dev/null && views 1 noindex
curl -s "$WRITER" | grep -iE "robots|canonical" | head -3 | sed 's/^/rank math writer head: /'
grep -E "PHP (Fatal|Warning)" "$W/wp-content/debug.log" 2>/dev/null | grep -i "recipes" | head -5 | sed 's/^/debug.log: /'
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null
mysql -u wptest -p"$pw" -h 127.0.0.1 -e "DROP DATABASE IF EXISTS g234smoke" </dev/null 2>/dev/null; rm -rf "$W"
echo "CANONICAL pass=$pass fail=$fail"
exit $(( fail > 0 ? 1 : 0 ))
