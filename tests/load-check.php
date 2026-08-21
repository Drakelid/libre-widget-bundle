<?php

/**
 * Class-load smoke test.
 *
 * WHY THIS EXISTS
 *
 * Version 1.0.0 shipped a hook class declared as:
 *
 *     class MenuEntry extends MenuEntryHook   // MenuEntryHook is an INTERFACE
 *
 * That is a compile-time fatal, not a syntax error, so `php -l` reported the file as
 * perfectly fine. The bug only surfaced when PHP actually loaded the class -- which,
 * because Laravel auto-discovers this package's service provider on every request,
 * meant a site-wide 500 on the production LibreNMS server rather than a broken widget.
 *
 * A fatal like that cannot be caught with try/catch. The only way to find it before
 * release is to load the class. This script does exactly that, one class per
 * subprocess, so a fatal shows up as a non-zero exit code instead of killing the run.
 *
 * Usage:  php tests/load-check.php
 * Exit:   0 = every class loaded, 1 = at least one failed
 */

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php is missing. Run: composer install\n");
    exit(1);
}

/*
 * Classes that can be loaded without a full LibreNMS installation.
 *
 * Anything extending LibreNMS core (App\Http\Controllers\Widgets\WidgetController)
 * cannot be loaded here, so the widget controllers and BundleWidgetController are
 * deliberately absent -- `php -l` and the acceptance checklist cover those. Everything
 * listed here depends only on librenms/plugin-interfaces and illuminate/*, both of
 * which are dev dependencies.
 */
$classes = [
    // The provider is the critical one: Laravel loads it on every request.
    'Drakelid\\NmsDashWidgets\\Providers\\WidgetServiceProvider',

    // Hooks are instantiated by the plugin manager during boot.
    'Drakelid\\NmsDashWidgets\\Hooks\\MenuEntry',

    // Support layer.
    'Drakelid\\NmsDashWidgets\\Support\\Assets',
    'Drakelid\\NmsDashWidgets\\Support\\Cast',
    'Drakelid\\NmsDashWidgets\\Support\\DeviceGroups',
    'Drakelid\\NmsDashWidgets\\Support\\Format',
    'Drakelid\\NmsDashWidgets\\Support\\SafeRegex',
    'Drakelid\\NmsDashWidgets\\Support\\Temperature',
    'Drakelid\\NmsDashWidgets\\Support\\Version',
    'Drakelid\\NmsDashWidgets\\Support\\WidgetCatalog',
    'Drakelid\\NmsDashWidgets\\Hooks\\Settings',
];

/**
 * Contracts a class must satisfy beyond merely loading.
 *
 * Loading proves the declaration is legal; these prove it is the right shape. The
 * MenuEntry entries below are precisely what 1.0.0 got wrong.
 */
$contracts = [
    'Drakelid\\NmsDashWidgets\\Hooks\\MenuEntry' => [
        'implements' => ['LibreNMS\\Interfaces\\Plugins\\Hooks\\MenuEntryHook'],
        'methods' => ['authorize', 'handle'],
    ],
    'Drakelid\\NmsDashWidgets\\Providers\\WidgetServiceProvider' => [
        'extends' => 'Illuminate\\Support\\ServiceProvider',
        'methods' => ['register', 'boot'],
    ],
    'Drakelid\\NmsDashWidgets\\Hooks\\Settings' => [
        'implements' => ['LibreNMS\\Interfaces\\Plugins\\Hooks\\SettingsHook'],
        'methods' => ['authorize', 'handle'],
        // authorize() must take NO parameters. PluginManager resolves them from the
        // container, and LibreNMS does not bind App\Models\User -- injecting one yields
        // an empty model whose can() checks always fail, which silently drops the hook
        // and renders 'missing view'. See Hooks/Settings.php.
        'no_params' => ['authorize'],
    ],
];

$failures = 0;

foreach ($classes as $class) {
    $script = sprintf(
        'require %s; if (!class_exists(%s, true)) { exit(2); } exit(0);',
        var_export($autoload, true),
        var_export($class, true)
    );

    $command = sprintf(
        '%s -d display_errors=1 -d error_reporting=-1 -r %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($script)
    );

    $output = [];
    $status = 0;
    exec($command, $output, $status);

    if ($status === 0) {
        printf("  [ ok ] %s\n", $class);
        continue;
    }

    $failures++;
    printf("  [FAIL] %s\n", $class);

    foreach ($output as $line) {
        if (trim($line) !== '') {
            printf("         %s\n", $line);
        }
    }

    if ($status === 2) {
        print("         class could not be autoloaded (wrong namespace or path?)\n");
    }
}

// Contract checks run in one process; every class above already loaded cleanly.
require $autoload;

foreach ($contracts as $class => $rules) {
    if (! class_exists($class)) {
        continue; // already reported above
    }

    foreach ($rules['implements'] ?? [] as $interface) {
        if (! in_array($interface, class_implements($class) ?: [], true)) {
            $failures++;
            printf("  [FAIL] %s does not implement %s\n", $class, $interface);
        }
    }

    if (isset($rules['extends']) && ! is_subclass_of($class, $rules['extends'])) {
        $failures++;
        printf("  [FAIL] %s does not extend %s\n", $class, $rules['extends']);
    }

    foreach ($rules['methods'] ?? [] as $method) {
        if (! method_exists($class, $method)) {
            $failures++;
            printf("  [FAIL] %s is missing method %s()\n", $class, $method);
        }
    }

    foreach ($rules['no_params'] ?? [] as $method) {
        if (! method_exists($class, $method)) {
            continue;
        }

        $count = (new ReflectionMethod($class, $method))->getNumberOfParameters();

        if ($count > 0) {
            $failures++;
            printf(
                "  [FAIL] %s::%s() declares %d parameter(s); it must declare none, or the"
                    . " plugin manager injects an empty model and the hook is dropped\n",
                $class,
                $method,
                $count
            );
        }
    }
}

if ($failures > 0) {
    printf("\n%d check(s) failed. Do NOT tag a release.\n", $failures);
    exit(1);
}

printf("\nAll %d classes load and satisfy their contracts.\n", count($classes));
exit(0);
