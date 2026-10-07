# Shared by activate.sh and rollback.sh. Expects WEBROOT and SHARED to be set.

mode() { stat -c %a "$1" 2>/dev/null || stat -f %Lp "$1"; }
point() { ln -sfn "$1" "$2.next"; mv -T "$2.next" "$2" 2>/dev/null || { rm -f "$2"; mv "$2.next" "$2"; }; }

# cPanel's MultiPHP Manager records the site's PHP version as a marked block in the web root .htaccess.
php_handler_block() {
    [ -f "$WEBROOT/.htaccess" ] || return 0
    sed -n '/^# php -- BEGIN cPanel-generated handler/,/^# php -- END cPanel-generated handler/p' "$WEBROOT/.htaccess"
}

# The PHP CLI matching the version the web server runs, so console commands see the same runtime.
site_php() {
    local v
    v="$(php_handler_block | sed -n 's/.*application\/x-httpd-\(ea-php[0-9]*\).*/\1/p' | head -1)"
    if [ -n "$v" ] && [ -x "/usr/local/bin/$v" ]; then echo "/usr/local/bin/$v"; else echo php; fi
}

publish() {
    local src="$1" handler root_mode
    local keep=(--exclude '/.htaccess' --exclude '/.well-known' --exclude '/cgi-bin' --exclude '/media' --exclude '/google*.html')
    # FTP accounts get their home directory inside the web root; those belong to the account owner.
    for d in "$WEBROOT"/*/; do
        if [ -e "${d}.ftpquota" ]; then keep+=(--exclude "/$(basename "$d")"); fi
    done
    find "$src" -type d -exec chmod 755 {} + && find "$src" -type f -exec chmod 644 {} +
    handler="$(php_handler_block)"
    root_mode="$(mode "$WEBROOT")"
    # No -g/-o: cPanel gives the web root group "nobody" so Apache can enter it; the account cannot set that group back.
    rsync -rlpt --delete "${keep[@]}" "$src/" "$WEBROOT/"
    { if [ -n "$handler" ]; then printf '%s\n\n' "$handler"; fi; cat "$src/.htaccess"; } > "$WEBROOT/.htaccess.next"
    chmod 644 "$WEBROOT/.htaccess.next"
    mv "$WEBROOT/.htaccess.next" "$WEBROOT/.htaccess"
    chmod "$root_mode" "$WEBROOT"
    ln -sfn "$SHARED/storage/public" "$WEBROOT/media"
}
