<?php

/**
 * Real host integration smoke test. Requires a migrated, seeded TEST installation
 * with this plugin installed/enabled. Never run against a production database.
 * Usage: php tests/librenms-integration.php /path/to/librenms USER_ID DASHBOARD_ID
 */
$host = rtrim($argv[1] ?? '', '/\\');
$userId = (int) ($argv[2] ?? 0);
$dashboardId = (int) ($argv[3] ?? 0);
if (! is_file($host . '/vendor/autoload.php') || ! $userId || ! $dashboardId) {
    fwrite(STDERR, "Requires a LibreNMS test installation, test user ID and dashboard ID.\n");
    exit(2);
}
chdir($host);
require $host . '/vendor/autoload.php';
$app = require $host . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    fwrite(STDERR, "Refusing to run: LibreNMS APP_ENV must be testing, using a dedicated test database.\n");
    exit(2);
}
$user = App\Models\User::findOrFail($userId);
$dashboard = App\Models\Dashboard::findOrFail($dashboardId);
Illuminate\Support\Facades\Auth::login($user);
if (! $user->can('view', $dashboard)) {
    throw new RuntimeException('The supplied dashboard must be visible to the test user');
}
$slugs = Drakelid\NmsDashWidgets\Support\WidgetCatalog::slugs();
if (count(Drakelid\NmsDashWidgets\Providers\WidgetServiceProvider::enabledWidgets()) !== count($slugs)) {
    throw new RuntimeException('Enable all widgets on the test installation before running');
}
$checked = 0;
Illuminate\Support\Facades\DB::beginTransaction();
try {
    foreach ($slugs as $slug) {
        $widget = App\Models\UserWidget::create([
            'user_id' => $userId, 'dashboard_id' => $dashboardId, 'widget' => $slug,
            'col' => 1, 'row' => 1, 'size_x' => 4, 'size_y' => 4, 'title' => '', 'refresh' => 60, 'settings' => [],
        ]);
        $layouts = Drakelid\NmsDashWidgets\Support\Presentation::layoutsFor($slug);
        foreach (array_merge(['settings'], $layouts) as $mode) {
            $widget->settings = ['layout' => $mode === 'settings' ? 'list' : $mode, 'show_history' => false, 'history_enabled' => false];
            $widget->save();
            $request = Illuminate\Http\Request::create('/ajax/dash/' . $slug, 'POST', [
                'id' => $widget->getKey(), 'settings' => $mode === 'settings', 'dimensions' => ['x' => 900, 'y' => 600],
            ]);
            $request->setUserResolver(fn () => $user);
            $app->instance('request', $request);
            Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
            $class = 'Drakelid\\NmsDashWidgets\\Http\\Controllers\\Widgets\\'
                . Drakelid\NmsDashWidgets\Support\WidgetCatalog::controller($slug);
            $controller = new $class();
            // Render explicitly so Blade failures throw at this call site.
            $view = $mode === 'settings' ? $controller->getSettingsView($request) : $controller->getView($request);
            $html = $view instanceof Illuminate\View\View ? $view->render() : (string) $view;
            if (trim($html) === '') {
                throw new RuntimeException("Empty rendering: $slug / $mode");
            }
            if ($mode === 'settings' && ! str_contains($html, 'name="title"')) {
                throw new RuntimeException("Missing settings form: $slug");
            }
            $checked++;
            echo "[ok] $slug / $mode\n";
        }
    }
} finally {
    Illuminate\Support\Facades\DB::rollBack();
}
echo "PASS: $checked real LibreNMS widget/settings renders; test widget inserts rolled back.\n";
