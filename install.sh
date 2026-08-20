#!/usr/bin/env bash
#
# install.sh -- install or update the LibreNMS Dashboard Widget Bundle.
#
#   drakelid/librenms-dashboard-widgets
#   https://packagist.org/packages/drakelid/librenms-dashboard-widgets
#
# Safe to re-run: the same command installs a fresh copy or updates an existing
# one. Run it on the LibreNMS server, as root or as the librenms user.
#
#   sudo ./install.sh
#   ./install.sh --dir /opt/librenms --version '^1.0'
#   ./install.sh --uninstall
#
set -euo pipefail

PACKAGE="drakelid/librenms-dashboard-widgets"
PLUGIN_NAME="nmsdashwidgets"
WIDGET_SLUGS=(
    device-group-down-count
    top-bandwidth-device-group
    uplink-utilization-overview
    top-device-temperatures
    flapping-devices
    recently-added-devices
)

LNMS_DIR="${LNMS_DIR:-/opt/librenms}"
LNMS_USER="${LNMS_USER:-librenms}"
VERSION="${VERSION:-}"
DO_ENABLE=1
DO_UNINSTALL=0
DRY_RUN=0

# ---------------------------------------------------------------------------
# output helpers
# ---------------------------------------------------------------------------

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    C_RESET=$'\033[0m'; C_RED=$'\033[31m'; C_GRN=$'\033[32m'
    C_YEL=$'\033[33m';  C_BLU=$'\033[36m'; C_DIM=$'\033[2m'
else
    C_RESET=; C_RED=; C_GRN=; C_YEL=; C_BLU=; C_DIM=
fi

step() { printf '\n%s==>%s %s\n' "$C_BLU" "$C_RESET" "$*"; }
ok()   { printf '  %s[ ok ]%s %s\n' "$C_GRN" "$C_RESET" "$*"; }
warn() { printf '  %s[warn]%s %s\n' "$C_YEL" "$C_RESET" "$*"; }
err()  { printf '  %s[fail]%s %s\n' "$C_RED" "$C_RESET" "$*" >&2; }
dim()  { printf '  %s%s%s\n' "$C_DIM" "$*" "$C_RESET"; }

die() { err "$*"; exit 1; }

usage() {
    cat <<EOF
Install or update the LibreNMS Dashboard Widget Bundle.

Usage: $(basename "$0") [options]

Options:
  --dir PATH        LibreNMS install directory (default: ${LNMS_DIR})
  --user NAME       User that owns the LibreNMS install (default: ${LNMS_USER})
  --version SPEC    Composer version constraint. Auto-detected when omitted:
                    the newest stable release if one is published, otherwise
                    'dev-main@dev'.
  --no-enable       Install only; do not enable the plugin.
  --uninstall       Remove the plugin instead of installing it.
  --dry-run         Print the commands that would run, without running them.
  -h, --help        Show this help.

Environment overrides: LNMS_DIR, LNMS_USER, VERSION, NO_COLOR
EOF
}

while [ $# -gt 0 ]; do
    case "$1" in
        --dir)       LNMS_DIR="${2:?--dir needs a path}"; shift 2 ;;
        --user)      LNMS_USER="${2:?--user needs a name}"; shift 2 ;;
        --version)   VERSION="${2:?--version needs a constraint}"; shift 2 ;;
        --no-enable) DO_ENABLE=0; shift ;;
        --uninstall) DO_UNINSTALL=1; shift ;;
        --dry-run)   DRY_RUN=1; shift ;;
        -h|--help)   usage; exit 0 ;;
        *)           usage >&2; die "Unknown option: $1" ;;
    esac
done

# ---------------------------------------------------------------------------
# run a command as the librenms user, from the install directory
#
# Composer and artisan must never run as root against a LibreNMS tree: doing so
# leaves root-owned files in vendor/ and bootstrap/cache/ that later break the
# web UI and the poller.
# ---------------------------------------------------------------------------

as_lnms() {
    local display="cd $LNMS_DIR && $*"

    if [ "$DRY_RUN" -eq 1 ]; then
        dim "would run: $display"
        return 0
    fi

    dim "\$ $display"

    if [ "$(id -un)" = "$LNMS_USER" ]; then
        ( cd "$LNMS_DIR" && "$@" )
    else
        # -H so composer's cache lands in the librenms home, not root's.
        sudo -H -u "$LNMS_USER" bash -c 'cd "$1" && shift && exec "$@"' _ "$LNMS_DIR" "$@"
    fi
}

# ---------------------------------------------------------------------------
# recovery helpers
#
# A half-installed plugin is not a harmless no-op. Laravel auto-discovers the
# package's service provider from composer.json on EVERY request, so a plugin
# that installs but cannot be loaded takes the entire LibreNMS UI down with a
# 500. Anything that can fail below must be able to undo itself.
# ---------------------------------------------------------------------------

composer_bin() {
    if [ -f "$LNMS_DIR/composer.phar" ]; then
        echo "php composer.phar"
    elif command -v composer >/dev/null 2>&1; then
        echo "composer"
    else
        echo ""
    fi
}

# Does the framework still boot? Cheap, and it is exactly what a web request needs.
librenms_boots() {
    [ "$DRY_RUN" -eq 1 ] && return 0
    as_lnms php artisan --version >/dev/null 2>&1
}

rollback() {
    step "Rolling back"
    dim "Leaving a partially installed plugin in place would 500 the whole UI,"
    dim "so it is being removed."

    if [ "$DRY_RUN" -eq 1 ]; then
        dim "skipped in --dry-run"
        return 0
    fi

    # 1. Delete the code first. Until it is gone, any attempt to boot Laravel --
    #    including composer's own post-autoload-dump hook -- can hit the fatal.
    if [ -d "$LNMS_DIR/vendor/$PACKAGE" ]; then
        rm -rf "${LNMS_DIR:?}/vendor/${PACKAGE:?}" && ok "removed vendor/$PACKAGE"
    fi

    # 2. Drop the cached manifest that still names our service provider.
    rm -f "$LNMS_DIR/bootstrap/cache/packages.php"           "$LNMS_DIR/bootstrap/cache/services.php"           "$LNMS_DIR/bootstrap/cache/routes-v7.php" 2>/dev/null || true
    ok "cleared the cached package manifest"

    # 3. Take it out of composer.json. --no-scripts keeps composer from invoking
    #    artisan while the tree is still inconsistent.
    local cbin
    cbin="$(composer_bin)"
    if [ -n "$cbin" ]; then
        # shellcheck disable=SC2086
        as_lnms $cbin remove "$PACKAGE" --update-no-dev --no-scripts --no-interaction             >/dev/null 2>&1 && ok "removed from composer.json"             || warn "could not edit composer.json automatically"
        # shellcheck disable=SC2086
        as_lnms $cbin dump-autoload --no-scripts --no-interaction >/dev/null 2>&1 || true
    else
        warn "composer not found; edit $LNMS_DIR/composer.json by hand if needed"
    fi

    # 4. composer remove does NOT touch vendor/composer/installed.json when the
    #    package is absent from composer.json -- it reports "not required ... has not
    #    been removed" and leaves the stale entry behind. Laravel's PackageManifest
    #    reads installed.json to auto-discover providers, so that leftover is enough
    #    on its own to 500 every request. Strip it explicitly.
    if command -v python3 >/dev/null 2>&1; then
        python3 - "$LNMS_DIR" "$PACKAGE" <<'PYEOF' || warn "could not clean installed.json"
import json, os, sys
base, pkg = sys.argv[1], sys.argv[2]
path = os.path.join(base, 'vendor', 'composer', 'installed.json')
if os.path.isfile(path):
    try:
        data = json.load(open(path, encoding='utf-8'))
        wrapped = isinstance(data, dict) and 'packages' in data
        items = data['packages'] if wrapped else data
        kept = [p for p in items if p.get('name') != pkg]
        if len(kept) != len(items):
            if wrapped:
                data['packages'] = kept
            else:
                data = kept
            json.dump(data, open(path, 'w', encoding='utf-8'), indent=4)
            print('  [ ok ] removed %s from installed.json' % pkg)
    except Exception as exc:
        print('  [warn] installed.json: %s' % exc)
PYEOF
    fi

    # 5. Regenerate the manifest and caches now that the tree is clean.
    as_lnms php artisan package:discover >/dev/null 2>&1 || true
    as_lnms php artisan route:clear >/dev/null 2>&1 || true
    as_lnms php artisan view:clear >/dev/null 2>&1 || true
    as_lnms php artisan cache:clear >/dev/null 2>&1 || true

    if librenms_boots; then
        ok "LibreNMS boots again"
    else
        err "LibreNMS still does not boot. Check storage/logs/laravel.log."
        dim "Manual recovery:"
        dim "  rm -rf $LNMS_DIR/vendor/$PACKAGE"
        dim "  rm -f $LNMS_DIR/bootstrap/cache/packages.php $LNMS_DIR/bootstrap/cache/services.php"
        dim "  cd $LNMS_DIR && composer remove $PACKAGE --update-no-dev --no-scripts"
    fi
}

# ---------------------------------------------------------------------------
# preflight
# ---------------------------------------------------------------------------

step "Checking the environment"

[ -d "$LNMS_DIR" ] || die "LibreNMS directory not found: $LNMS_DIR (use --dir)"
[ -f "$LNMS_DIR/lnms" ] || die "$LNMS_DIR does not look like a LibreNMS install (no ./lnms)"
ok "LibreNMS directory: $LNMS_DIR"

id "$LNMS_USER" >/dev/null 2>&1 || die "User '$LNMS_USER' does not exist (use --user)"
ok "Install owner: $LNMS_USER"

if [ "$(id -un)" != "$LNMS_USER" ]; then
    command -v sudo >/dev/null 2>&1 || die "Not running as '$LNMS_USER' and sudo is unavailable."
    ok "Will run commands via sudo -u $LNMS_USER"
fi

command -v php >/dev/null 2>&1 || die "php not found in PATH"
PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
if php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);'; then
    ok "PHP $PHP_VERSION"
else
    die "PHP $PHP_VERSION is too old; this plugin requires PHP 8.2 or newer."
fi

if LNMS_VERSION="$(cd "$LNMS_DIR" && git describe --tags --abbrev=0 2>/dev/null)"; then
    ok "LibreNMS version: $LNMS_VERSION"
    dim "requires 25.7 or newer for plugin-provided dashboard widgets"
else
    warn "Could not determine the LibreNMS version; continuing."
fi

# ---------------------------------------------------------------------------
# uninstall
# ---------------------------------------------------------------------------

if [ "$DO_UNINSTALL" -eq 1 ]; then
    step "Removing $PACKAGE"
    as_lnms ./lnms plugin:remove "$PACKAGE" || warn "plugin:remove reported a problem; continuing with cache cleanup."

    step "Rebuilding caches"
    as_lnms php artisan route:clear
    as_lnms php artisan view:clear
    as_lnms php artisan route:cache
    ok "Removed. Existing dashboard widgets from this bundle will now show an error panel."
    dim "Delete them from each dashboard, or re-install to restore."
    exit 0
fi

# ---------------------------------------------------------------------------
# resolve the version to install
#
# The package publishes a dev-main branch and, once tagged, stable releases.
# LibreNMS's own composer.json sets minimum-stability=stable, so a bare
# 'dev-main' is refused; the '@dev' flag is what makes it acceptable.
# ---------------------------------------------------------------------------

if [ -z "$VERSION" ]; then
    step "Resolving the version to install"

    LATEST_STABLE=""
    if command -v curl >/dev/null 2>&1; then
        LATEST_STABLE="$(curl -fsS --max-time 15 \
            "https://repo.packagist.org/p2/${PACKAGE}.json" 2>/dev/null \
            | tr ',' '\n' | grep -o '"version":"v\{0,1\}[0-9][^"]*"' \
            | sed 's/.*:"//; s/"$//; s/^v//' \
            | grep -Ev 'dev|alpha|beta|RC' | sort -V | tail -1 || true)"
    fi

    if [ -n "$LATEST_STABLE" ]; then
        VERSION="^${LATEST_STABLE%%.*}.0"
        ok "Newest stable release: $LATEST_STABLE -> using constraint '$VERSION'"
    else
        VERSION="dev-main@dev"
        warn "No stable release is published on Packagist yet; using '$VERSION'."
        dim "Tag a release (git tag v1.0.0 && git push --tags) to install stable versions."
    fi
else
    ok "Using the requested version constraint: $VERSION"
fi

# ---------------------------------------------------------------------------
# install / update
# ---------------------------------------------------------------------------

step "Installing $PACKAGE ($VERSION)"
dim "lnms plugin:add runs 'composer require' against the LibreNMS tree;"
dim "re-running it updates an already-installed copy."

if ! as_lnms ./lnms plugin:add "$PACKAGE" "$VERSION"; then
    err "plugin:add failed."
    dim "Common causes:"
    dim "  - A PHP fatal error in the plugin itself (look for 'PHP Fatal error' above)."
    dim "    That means a broken plugin release, not a broken server. Report it, or"
    dim "    pin a known-good version: --version '1.0.0'"
    dim "  - No stable tag published yet. Retry with: --version 'dev-main@dev'"
    dim "  - Composer could not reach packagist.org (proxy or firewall)."
    dim "  - vendor/ contains root-owned files from an earlier 'sudo composer' run."
    dim "    Fix with: chown -R $LNMS_USER:$LNMS_USER $LNMS_DIR/vendor $LNMS_DIR/composer.*"
    rollback
    exit 1
fi
ok "Package installed"

# A package can install cleanly and still be unloadable -- a fatal in the plugin's
# own code surfaces only once Laravel tries to register its service provider.
step "Checking that LibreNMS still boots"
if librenms_boots; then
    ok "Framework boots with the plugin loaded"
else
    err "LibreNMS no longer boots with this plugin installed."
    dim "This is a broken plugin release, not a broken server."
    rollback
    exit 1
fi

# ---------------------------------------------------------------------------
# ownership
# ---------------------------------------------------------------------------

if [ "$(id -u)" -eq 0 ] && [ "$DRY_RUN" -eq 0 ]; then
    step "Normalising file ownership"
    chown -R "$LNMS_USER":"$LNMS_USER" \
        "$LNMS_DIR/vendor" \
        "$LNMS_DIR/composer.json" \
        "$LNMS_DIR/composer.lock" \
        "$LNMS_DIR/bootstrap/cache" 2>/dev/null || true
    [ -f "$LNMS_DIR/composer.plugins.json" ] && \
        chown "$LNMS_USER":"$LNMS_USER" "$LNMS_DIR/composer.plugins.json"
    ok "Ownership set to $LNMS_USER"
fi

# ---------------------------------------------------------------------------
# caches
#
# This is the step people miss. LibreNMS caches routes and views in production,
# and dashboard widgets are discovered by SCANNING THE ROUTE TABLE -- so a stale
# route cache makes a correctly installed plugin appear to do nothing at all.
#
# config: is deliberately left alone. LibreNMS does not cache its config, and
# running config:cache here would change behaviour beyond this plugin.
# ---------------------------------------------------------------------------

step "Clearing caches"
as_lnms php artisan route:clear && ok "route cache cleared"
as_lnms php artisan view:clear  && ok "view cache cleared"

# ---------------------------------------------------------------------------
# enable
# ---------------------------------------------------------------------------

if [ "$DO_ENABLE" -eq 1 ]; then
    step "Enabling the plugin"

    if as_lnms ./lnms plugin:enable "$PLUGIN_NAME"; then
        ok "Plugin enabled (this also rebuilds the route cache)"
    else
        warn "Could not enable '$PLUGIN_NAME' from the CLI."
        dim "LibreNMS only knows about a plugin once it has been discovered, which"
        dim "can take one page load. Enable it in the web UI instead:"
        dim "  Overview -> Plugins -> Plugins Admin -> enable '$PLUGIN_NAME'"
        dim "or re-run this script."
    fi
fi

step "Rebuilding the route cache"
as_lnms php artisan route:cache && ok "routes cached"

# ---------------------------------------------------------------------------
# verify
# ---------------------------------------------------------------------------

step "Verifying the widget routes"

if [ "$DRY_RUN" -eq 1 ]; then
    dim "skipped in --dry-run"
else
    ROUTES="$(as_lnms php artisan route:list --path=ajax/dash 2>/dev/null || true)"
    FOUND=0
    MISSING=()

    for slug in "${WIDGET_SLUGS[@]}"; do
        if printf '%s' "$ROUTES" | grep -q "ajax/dash/$slug"; then
            FOUND=$((FOUND + 1))
        else
            MISSING+=("$slug")
        fi
    done

    if [ "$FOUND" -eq "${#WIDGET_SLUGS[@]}" ]; then
        ok "All ${#WIDGET_SLUGS[@]} widget routes are registered"
    elif [ "$FOUND" -gt 0 ]; then
        warn "Only $FOUND of ${#WIDGET_SLUGS[@]} widget routes registered."
        for slug in "${MISSING[@]}"; do dim "missing: ajax/dash/$slug"; done
    else
        warn "No widget routes found."
        dim "The plugin is installed but not enabled, or the route cache is stale."
        dim "Enable it under Overview -> Plugins -> Plugins Admin, then re-run this script."
    fi
fi

# ---------------------------------------------------------------------------
# done
# ---------------------------------------------------------------------------

step "Done"
cat <<EOF
  Add the widgets from any dashboard: Add Widget ->

    Device Group Down Count
    Top Bandwidth Usage by Device Group
    Uplink Utilization Overview
    Top Device Temperatures
    Flapping Devices / Unstable Links
    Recently Added Devices

  If the widgets do not appear, the route cache is the usual culprit:

    cd $LNMS_DIR && sudo -u $LNMS_USER php artisan route:clear

  To update later, just run this script again.
EOF
