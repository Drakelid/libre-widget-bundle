#!/usr/bin/env bash
#
# recover.sh -- restore a LibreNMS server left broken by a failed install of
#               drakelid/librenms-dashboard-widgets.
#
# Symptom this fixes:
#   "Whoops, looks like something went wrong"  on every page, with
#   storage/logs/laravel.log showing one of:
#     Class "Drakelid\NmsDashWidgets\Providers\WidgetServiceProvider" not found
#     Class Drakelid\NmsDashWidgets\Hooks\MenuEntry cannot extend interface ...
#
# Cause:
#   The package declares a Laravel service provider via composer's auto-discovery
#   (extra.laravel.providers). Laravel therefore loads that class on EVERY request.
#   If the package is registered in composer.json but its code is missing or does
#   not compile, the whole application fails to boot -- not just the plugin.
#
# This script fully DEREGISTERS the package, which is what makes the site boot.
# It does not need network access.
#
# Usage:  sudo bash recover.sh            (or: sudo bash recover.sh /opt/librenms)
#
set -uo pipefail          # deliberately NOT -e: we want to try every fallback

LNMS_DIR="${1:-/opt/librenms}"
LNMS_USER="${LNMS_USER:-librenms}"
PACKAGE="drakelid/librenms-dashboard-widgets"
VENDOR_DIR="$LNMS_DIR/vendor/drakelid"

say()  { printf '\n\033[36m==>\033[0m %s\n' "$*"; }
ok()   { printf '  \033[32m[ ok ]\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m[warn]\033[0m %s\n' "$*"; }
bad()  { printf '  \033[31m[fail]\033[0m %s\n' "$*"; }

run_as() { sudo -H -u "$LNMS_USER" bash -c "cd '$LNMS_DIR' && $*"; }
boots()  { run_as "php artisan --version" >/dev/null 2>&1; }

[ -d "$LNMS_DIR" ] || { bad "No such directory: $LNMS_DIR"; exit 1; }
[ -f "$LNMS_DIR/lnms" ] || { bad "$LNMS_DIR is not a LibreNMS install"; exit 1; }
cd "$LNMS_DIR" || exit 1

# ---------------------------------------------------------------------------
say "Current state"
# ---------------------------------------------------------------------------
grep -q "$PACKAGE" composer.json 2>/dev/null \
    && warn "still listed in composer.json" || ok "not in composer.json"
grep -q "$PACKAGE" composer.plugins.json 2>/dev/null \
    && warn "still listed in composer.plugins.json" || ok "not in composer.plugins.json"
grep -q "$PACKAGE" vendor/composer/installed.json 2>/dev/null \
    && warn "still listed in vendor/composer/installed.json" || ok "not in installed.json"
[ -d "$VENDOR_DIR" ] && warn "vendor code present" || ok "vendor code absent"
boots && { ok "LibreNMS already boots -- nothing to repair"; exit 0; } || bad "LibreNMS does not boot"

# ---------------------------------------------------------------------------
say "Step 1: deregister the package with composer (--no-scripts)"
# ---------------------------------------------------------------------------
# --no-scripts matters: composer's post-autoload-dump hook runs artisan, and
# artisan is exactly what is currently crashing.
if [ -f scripts/composer_wrapper.php ]; then
    run_as "COMPOSER=composer.plugins.json php scripts/composer_wrapper.php remove '$PACKAGE' --no-update --no-scripts --no-interaction" >/dev/null 2>&1
    run_as "php scripts/composer_wrapper.php remove '$PACKAGE' --update-no-dev --no-scripts --no-interaction" \
        && ok "composer removed the package" || warn "composer removal reported an error"
else
    warn "scripts/composer_wrapper.php not found; skipping to manual edit"
fi

# ---------------------------------------------------------------------------
say "Step 2: manual cleanup of anything composer left behind"
# ---------------------------------------------------------------------------
python3 - "$LNMS_DIR" "$PACKAGE" <<'PY' || warn "python3 cleanup unavailable; check the files by hand"
import json, os, sys
base, pkg = sys.argv[1], sys.argv[2]

def prune_requires(path):
    if not os.path.isfile(path):
        return
    try:
        data = json.load(open(path, encoding='utf-8'))
    except Exception as exc:
        print('  [warn] could not parse %s: %s' % (path, exc)); return
    changed = False
    for key in ('require', 'require-dev'):
        if isinstance(data.get(key), dict) and pkg in data[key]:
            del data[key][pkg]; changed = True
    if changed:
        json.dump(data, open(path, 'w', encoding='utf-8'), indent=4)
        print('  [ ok ] removed %s from %s' % (pkg, os.path.basename(path)))

prune_requires(os.path.join(base, 'composer.json'))
prune_requires(os.path.join(base, 'composer.plugins.json'))

# installed.json is what Laravel's PackageManifest reads to auto-discover providers
ij = os.path.join(base, 'vendor/composer/installed.json')
if os.path.isfile(ij):
    try:
        data = json.load(open(ij, encoding='utf-8'))
        pkgs = data.get('packages', data)
        kept = [p for p in pkgs if p.get('name') != pkg]
        if len(kept) != len(pkgs):
            if isinstance(data, dict) and 'packages' in data:
                data['packages'] = kept
            else:
                data = kept
            json.dump(data, open(ij, 'w', encoding='utf-8'), indent=4)
            print('  [ ok ] removed %s from installed.json' % pkg)
    except Exception as exc:
        print('  [warn] could not edit installed.json: %s' % exc)
PY

# Drop leftover code and any autoload references to it
[ -d "$VENDOR_DIR" ] && { rm -rf "$VENDOR_DIR" && ok "deleted vendor/drakelid"; }
sed -i "\#drakelid/librenms-dashboard-widgets#d" vendor/composer/autoload_psr4.php 2>/dev/null || true
sed -i "\#Drakelid#d" vendor/composer/autoload_psr4.php 2>/dev/null || true

# ---------------------------------------------------------------------------
say "Step 3: clear the cached provider manifest"
# ---------------------------------------------------------------------------
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
      bootstrap/cache/config.php bootstrap/cache/routes-v7.php 2>/dev/null
ok "manifests cleared"

# ---------------------------------------------------------------------------
say "Step 4: regenerate the autoloader"
# ---------------------------------------------------------------------------
if [ -f scripts/composer_wrapper.php ]; then
    run_as "php scripts/composer_wrapper.php dump-autoload --no-scripts --no-interaction" >/dev/null 2>&1 \
        && ok "autoloader regenerated" || warn "dump-autoload failed"
fi

# ---------------------------------------------------------------------------
say "Step 5: does it boot?"
# ---------------------------------------------------------------------------
if boots; then
    ok "LibreNMS boots"
else
    warn "still not booting -- installing a no-op placeholder provider"
    # Last resort: give Laravel the class it is asking for, doing nothing.
    mkdir -p "$VENDOR_DIR/librenms-dashboard-widgets/src/Providers"
    cat > "$VENDOR_DIR/librenms-dashboard-widgets/src/Providers/WidgetServiceProvider.php" <<'PHPEOF'
<?php

namespace Drakelid\NmsDashWidgets\Providers;

use Illuminate\Support\ServiceProvider;

/** Placeholder so the application can boot. Registers nothing. */
class WidgetServiceProvider extends ServiceProvider
{
    public function register(): void {}
    public function boot(): void {}
}
PHPEOF
    chown -R "$LNMS_USER":"$LNMS_USER" "$VENDOR_DIR"
    rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
    boots && ok "LibreNMS boots with the placeholder" || bad "still not booting"
fi

# ---------------------------------------------------------------------------
say "Step 6: fix ownership, clear caches, drop opcache"
# ---------------------------------------------------------------------------
chown -R "$LNMS_USER":"$LNMS_USER" \
    "$LNMS_DIR/vendor" "$LNMS_DIR/bootstrap/cache" \
    "$LNMS_DIR/composer.json" "$LNMS_DIR/composer.lock" 2>/dev/null
[ -f "$LNMS_DIR/composer.plugins.json" ] && chown "$LNMS_USER":"$LNMS_USER" "$LNMS_DIR/composer.plugins.json"
ok "ownership restored to $LNMS_USER"

run_as "php artisan package:discover" >/dev/null 2>&1 && ok "providers rediscovered"
run_as "php artisan cache:clear"  >/dev/null 2>&1 && ok "app cache cleared"
run_as "php artisan route:clear"  >/dev/null 2>&1 && ok "route cache cleared"
run_as "php artisan view:clear"   >/dev/null 2>&1 && ok "view cache cleared"

for svc in php8.4-fpm php8.3-fpm php8.2-fpm php-fpm; do
    if systemctl list-units --type=service --all 2>/dev/null | grep -q "$svc"; then
        systemctl restart "$svc" && ok "restarted $svc"
        break
    fi
done

# ---------------------------------------------------------------------------
say "Result"
# ---------------------------------------------------------------------------
if boots; then
    ok "LibreNMS is back. Reload the web UI."
    printf '\n  Remove the leftover DB row for the plugin (optional):\n'
    printf "    cd %s && sudo -u %s ./lnms plugin:remove %s\n" "$LNMS_DIR" "$LNMS_USER" "$PACKAGE"
    exit 0
fi

bad "LibreNMS still does not boot."
printf '\n  Show the current error with:\n'
printf '    sudo tail -n 30 %s/storage/logs/laravel.log\n' "$LNMS_DIR"
printf '    cd %s && sudo -u %s php artisan --version\n\n' "$LNMS_DIR" "$LNMS_USER"
exit 1
