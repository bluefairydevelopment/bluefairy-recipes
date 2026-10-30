# Writer pages under SEO plugins (rig only; never shipped). Builds its own WordPress 7.1 at
# ~/g232wp, database g232smoke, php -S 127.0.0.1:8098, and requests the writer page, the
# writers listing, the writer's second page and (as a control) the recipe archive with no SEO
# plugin, BlueFairy SEO Toolkit (+ Pro), Yoast SEO, Rank Math and All in One SEO, each with its defaults and
# then with author archives switched off. A writer view must answer 200 (no redirect), its
# <title> must start with the writer's name ("Recipe writers" for the listing), and it must
# carry exactly one canonical naming itself (or none where the SEO plugin noindexes it).
#   SRC=<plugin dir>   the Recipes tree to install (default ~/g232/bluefairy-recipes)
#   ZIPS=<dir>         holds bluefairy-seo-toolkit-1.1.2.zip and bluefairy-seo-toolkit-pro-1.2.0.zip
# Exit status: 1 when any check fails, else 0.
set -u
SRC=${SRC:-~/g232/bluefairy-recipes}; ZIPS=${ZIPS:-~/g234/zips}
W=~/g232wp; PORT=${PORT:-8098}; U="http://127.0.0.1:$PORT"
pw=$(grep DB_PASSWORD ~/Sites/wp-testing/wp-config.php | sed -E "s/.*'DB_PASSWORD', '([^']*)'.*/\1/")
mysql -u wptest -p"$pw" -h 127.0.0.1 -e "DROP DATABASE IF EXISTS g232smoke; CREATE DATABASE g232smoke" </dev/null 2>/dev/null || { echo "DB CREATE FAILED"; exit 1; }
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null; rm -rf "$W"; mkdir -p "$W"; cd "$W" || exit 2
sleep 1; if ss -ltn 2>/dev/null | grep -qE "127\.0\.0\.1:$PORT\b"; then echo "PORT $PORT IN USE by another process (another session's server?): set PORT=<free port>"; exit 2; fi # A request would reach that server, not ours.
wp core download --version=7.1 --quiet </dev/null || exit 2
printf "define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );\n" > /tmp/g232-extra.php
wp config create --dbname=g232smoke --dbuser=wptest --dbpass="$pw" --dbhost=127.0.0.1 --quiet --extra-php < /tmp/g232-extra.php
grep -q "WP_DEBUG_LOG', true" wp-config.php || { echo "EXTRA PHP LOST"; exit 2; }
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
wp eval '
$w = wp_insert_user( [ "user_login" => "ann", "user_pass" => wp_generate_password(), "user_email" => "ann@example.test", "display_name" => "Ann Writer", "role" => "bfrc_writer" ] );
update_user_meta( $w, "description", "Soups and stews." );
$t = wp_insert_user( [ "user_login" => "tom", "user_pass" => wp_generate_password(), "user_email" => "tom@example.test", "display_name" => "Tom & Jerry", "role" => "bfrc_writer" ] );
for ( $i = 1; $i <= 5; $i++ ) {
	wp_insert_post( [ "post_type" => "bfrc_recipe", "post_status" => "publish", "post_title" => "Soup $i", "post_name" => "soup-$i", "post_author" => $w ] );
}
wp_insert_post( [ "post_type" => "bfrc_recipe", "post_status" => "publish", "post_title" => "Stew", "post_name" => "stew", "post_author" => $t ] );
$s = get_option( "bfrc_settings" ); $s["per_page"] = 2; update_option( "bfrc_settings", $s );
echo "per_page=" . get_option( "bfrc_settings" )["per_page"] . "\n";' </dev/null
wp rewrite flush --quiet </dev/null
PHP_CLI_SERVER_WORKERS=4 setsid nohup php -S 127.0.0.1:$PORT -t "$W" >/tmp/g232-server.log 2>&1 </dev/null & disown
sleep 2
ARCH=$(wp eval 'echo get_post_type_archive_link("bfrc_recipe");' </dev/null)
LIST="${ARCH}chef/"; WRITER="${ARCH}chef/ann/"; PAGE2="${WRITER}page/2/"; TOM="${ARCH}chef/tom/"
echo "archive=$ARCH writer=$WRITER"
pass=0; fail=0
ok(){ pass=$((pass+1)); echo "PASS [$STATE] $1"; }
no(){ fail=$((fail+1)); echo "FAIL [$STATE] $1"; }
probe(){ # url title-prefix label [noindex-ok]
	local out code loc html title n hrefs
	out=$(curl -s -o /tmp/g232-body.html -w '%{http_code} %{redirect_url}' "$1"); code=${out%% *}; loc=${out#* }
	html=$(cat /tmp/g232-body.html)
	title=$(grep -oE '<title[^>]*>[^<]*</title>' <<<"$html" | head -1 | sed -E 's/<[^>]+>//g')
	n=$(grep -oE "<link[^>]*rel=[\"']canonical[\"'][^>]*>" <<<"$html" | wc -l | tr -d ' ')
	hrefs=$(grep -oE "<link[^>]*rel=[\"']canonical[\"'][^>]*>" <<<"$html" | grep -oE "href=[\"'][^\"']*" | cut -c7- | paste -sd' ')
	if [ "$code" = 200 ]; then ok "$3: 200"; else no "$3: $code -> '$loc' (want 200, no redirect)"; return; fi
	case "$title" in "$2"*) ok "$3: title '$title'";; *) no "$3: title '$title' (want it to start '$2')";; esac
	if [ "$n" = 1 ] && [ "$hrefs" = "$1" ]; then ok "$3: canonical $hrefs"
	elif [ "${4:-}" = noindex ] && [ "$n" = 0 ] && grep -qE "name=[\"']robots[\"'][^>]*noindex" <<<"$html"; then ok "$3: noindexed by the SEO plugin, no canonical"
	else no "$3: $n canonical '$hrefs' (want 1, $1)"; fi
}
views(){
	probe "$WRITER" "Ann Writer" "writer page" "${1:-}"
	probe "$PAGE2" "Ann Writer" "writer page 2" "${1:-}"
	probe "$TOM" "Tom &amp; Jerry" "writer with & in the name (escaped once)" "${1:-}"
	probe "$LIST" "Recipe writers" "writers listing"
	local code; code=$(curl -s -o /dev/null -w '%{http_code}' "$ARCH"); [ "$code" = 200 ] && ok "archive control: 200" || no "archive control: $code"
}
STATE="no SEO plugin"; views
STATE="SEO Toolkit"; wp plugin activate bluefairy-seo-toolkit --quiet </dev/null; views
STATE="SEO Toolkit + Pro"; wp plugin activate bluefairy-seo-toolkit-pro --quiet </dev/null; views
wp plugin deactivate bluefairy-seo-toolkit-pro bluefairy-seo-toolkit --quiet </dev/null
STATE="Yoast defaults"; wp plugin activate wordpress-seo --quiet </dev/null && views
wp eval '$o = get_option( "wpseo_titles" ); $o["disable-author"] = true; update_option( "wpseo_titles", $o );' </dev/null
STATE="Yoast, author archives off"; views
wp eval '$o = get_option( "wpseo_titles" ); $o["disable-author"] = false; $o["noindex-author-wpseo"] = true; update_option( "wpseo_titles", $o );' </dev/null
STATE="Yoast, author archives noindexed"; views noindex
wp plugin deactivate wordpress-seo --quiet </dev/null
wp option update rank_math_wizard_completed 1 --quiet </dev/null; wp option update rank_math_registration_skip 1 --quiet </dev/null # Rank Math prints no head before its setup wizard
STATE="Rank Math defaults"; wp plugin activate seo-by-rank-math --quiet </dev/null && views noindex
wp eval '$o = get_option( "rank-math-options-titles", [] ); $o["disable_author_archives"] = "on"; update_option( "rank-math-options-titles", $o );' </dev/null
STATE="Rank Math, author archives off"; views noindex
wp plugin deactivate seo-by-rank-math --quiet </dev/null
STATE="All in One SEO defaults"; wp plugin activate all-in-one-seo-pack --quiet </dev/null && views
wp eval '$o = json_decode( (string) get_option( "aioseo_options" ), true ); if ( is_array( $o ) ) { $o["searchAppearance"]["archives"]["author"]["show"] = false; update_option( "aioseo_options", wp_json_encode( $o ) ); echo "aioseo author archives off\n"; } else { echo "AIOSEO OPTIONS NOT FOUND\n"; }' </dev/null
STATE="All in One SEO, author archives off"; views noindex
grep -E "PHP (Fatal|Warning|Notice|Deprecated)" "$W/wp-content/debug.log" 2>/dev/null | grep -i "recipes" | head -5 | sed 's/^/debug.log: /'
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null
mysql -u wptest -p"$pw" -h 127.0.0.1 -e "DROP DATABASE IF EXISTS g232smoke" </dev/null 2>/dev/null; rm -rf "$W"
echo "WRITER-SEO pass=$pass fail=$fail"
exit $(( fail > 0 ? 1 : 0 ))
