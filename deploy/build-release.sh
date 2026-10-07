#!/usr/bin/env bash
# Builds a self-contained production release archive for cPanel:
#
#   release/backend/       PHP API, console, migrations, production Composer dependencies
#   release/public_html/   production SPA build + .htaccess, api.php, .user.ini
#   release/deploy/        server-side activate / rollback scripts and the env template
#   release/VERSION        commit, build time and whether the working tree was clean
#
# Nothing secret is included: no .env files, no storage contents, no tests or dev dependencies.
# Usage:  deploy/build-release.sh            (from anywhere inside the repository)
set -euo pipefail

SITE_URL="https://www.hansterahiansh.com"

ROOT="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
cd "$ROOT"

COMMIT="$(git rev-parse --short=12 HEAD)"
DIRTY="$(git status --porcelain | grep -q . && echo yes || echo no)"
STAMP="$(date -u +%Y%m%d%H%M%S)"
NAME="advisory-${STAMP}-${COMMIT}"
OUT="$ROOT/build"
WORK="$OUT/$NAME"

if [ "$DIRTY" = "yes" ] && [ "${ALLOW_DIRTY:-0}" != "1" ]; then
    echo "The working tree has uncommitted changes. Commit first so the release maps to a commit," >&2
    echo "or set ALLOW_DIRTY=1 to build anyway (VERSION will record dirty=yes)." >&2
    exit 1
fi

rm -rf "$WORK"
mkdir -p "$WORK/backend" "$WORK/public_html" "$WORK/deploy"

echo "==> Backend tests"
(cd backend && env -u ENV_PATH -u STORAGE_PATH APP_ENV=testing vendor/bin/phpunit --no-progress --fail-on-skipped)

echo "==> Backend sources"
rsync -a \
    --exclude '.env' --exclude '.env.*' --exclude '.phpunit*' --exclude 'phpunit.xml' \
    --exclude 'vendor/' --exclude 'tests/' --exclude 'storage/' \
    backend/ "$WORK/backend/"
cp backend/.env.example "$WORK/backend/.env.example"
mkdir -p "$WORK/backend/storage"/{logs,private,public,cache}

echo "==> Backend production dependencies"
(cd "$WORK/backend" && composer install --no-dev --classmap-authoritative --no-interaction --no-progress --quiet)

echo "==> Frontend lint, typecheck, tests and production build"
(
    cd frontend
    npm run lint
    npx tsc -b
    npx vitest run --passWithNoTests
    VITE_APP_ENV=production VITE_SITE_URL="$SITE_URL" npx vite build --mode production --outDir "$WORK/public_html" --emptyOutDir
)
cp deploy/cpanel/public_html/.htaccess deploy/cpanel/public_html/api.php deploy/cpanel/public_html/.user.ini "$WORK/public_html/"

cp deploy/cpanel/activate.sh deploy/cpanel/rollback.sh deploy/cpanel/lib.sh deploy/cpanel/env.production.template "$WORK/deploy/"
chmod 755 "$WORK/deploy/"*.sh

printf 'commit=%s\ndirty=%s\nbuilt_at=%s\nsite=%s\n' "$COMMIT" "$DIRTY" "$STAMP" "$SITE_URL" > "$WORK/VERSION"

echo "==> Secret checks"
if find "$WORK" -name '.env' -o -name '.env.*' ! -name '.env.example' | grep -q .; then
    echo "An environment file ended up in the release." >&2; exit 1
fi
if find "$WORK/public_html" -name '*.map' | grep -q .; then
    echo "Source maps ended up in the release." >&2; exit 1
fi
if grep -rEl 'rzp_(test|live)_[A-Za-z0-9]{8,}|sk_(test|live)_[A-Za-z0-9]{8,}|rk_(test|live)_[A-Za-z0-9]{8,}|whsec_[A-Za-z0-9]{8,}|GOCSPX-|-----BEGIN [A-Z ]*PRIVATE KEY' \
        "$WORK/public_html" "$WORK/backend/app" "$WORK/backend/config" "$WORK/backend/routes" "$WORK/backend/database" "$WORK/deploy"; then
    echo "A credential-like string was found in the files above." >&2; exit 1
fi
if grep -rEl '127\.0\.0\.1|localhost:5173' "$WORK/public_html/assets"; then
    echo "A development URL was compiled into the frontend bundle." >&2; exit 1
fi

echo "==> Archive"
COPYFILE_DISABLE=1 tar -C "$OUT" --no-xattrs -czf "$OUT/$NAME.tar.gz" "$NAME" 2>/dev/null \
    || COPYFILE_DISABLE=1 tar -C "$OUT" -czf "$OUT/$NAME.tar.gz" "$NAME"
(cd "$OUT" && shasum -a 256 "$NAME.tar.gz" > "$NAME.tar.gz.sha256")
rm -rf "$WORK"

echo
echo "Release: build/$NAME.tar.gz"
cat "$OUT/$NAME.tar.gz.sha256"
