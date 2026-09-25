<?php

// php tests/operations-regression.php
// Execute the real map controller and flap summary with host model/query boundaries doubled.
namespace App\Http\Controllers\Widgets {
    class WidgetController
    {
        protected $settings = null;
        public static array $fixtureSettings = [];
        public function getSettings($settingsView = false): array { return array_replace($this->defaults, self::$fixtureSettings); }
        public function authorize($ability, $model): void { if (! $GLOBALS['mapUser']->deviceAccess) throw new \RuntimeException('403'); }
    }
}
namespace Illuminate\Http {
    class Request
    {
        public function __construct(private int $id) {}
        public function input($key): mixed { return $this->id; }
        public function validate($rules): void { if ($this->id < 1) throw new \RuntimeException('422'); }
        public function user(): \App\Models\User { return $GLOBALS['mapUser']; }
    }
    class JsonResponse { public function __construct(public array $data) {} }
}
namespace App\Facades {
    class LibrenmsConfig
    {
        public static function get($key, $default = null): mixed { return $key === 'device_types' ? [] : false; }
    }
}
namespace App\Models {
    class User
    {
        public bool $deviceAccess = true;
        public bool $dashboardAccess = true;
        public function can($ability, $model): bool { return $this->dashboardAccess; }
    }
    class UserWidget
    {
        public static string $kind = 'offline-devices-map';
        public static function findOrFail($id): object
        {
            if ($id !== 1) throw new \RuntimeException('404');
            return (object) ['widget' => self::$kind, 'dashboard' => (object) ['id' => 1]];
        }
    }
    #[\AllowDynamicProperties]
    class Device
    {
        public static array $fixtures = [];
        public function __construct(array $values) { foreach ($values as $key => $value) $this->$key = $value; }
        public static function hasAccess($user): \MapFixtureQuery { return new \MapFixtureQuery(array_filter(self::$fixtures, fn ($device) => $device->accessible)); }
        public function displayName(): string { return $this->name; }
        public function isUnderMaintenance(): bool { return $this->maintenance; }
    }
}
namespace Drakelid\NmsDashWidgets\Support {
    class DeviceGroups
    {
        public static function ids($values): array { return array_map('intval', $values); }
        public static function accessibleIds($user, array $ids): array { return array_values(array_intersect([1, 2], $ids)); }
        public static function scopeToDevices($query, array $ids): void { if ($ids) $query->filter(fn ($device) => (bool) array_intersect($device->groups, $ids)); }
    }
}
namespace LibreNMS\Util {
    class Url { public static function deviceUrl($device): string { return '/device/' . $device->device_id; } }
}
namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';

    class MapFixtureQuery
    {
        public function __construct(private array $devices) {}
        public function filter($predicate): static { $this->devices = array_values(array_filter($this->devices, $predicate)); return $this; }
        public function with(...$args): static { return $this; }
        public function orderBy(...$args): static { return $this; }
        public function where($column, $value): static { return $this->filter(fn ($device) => $device->$column == $value); }
        public function whereIn($column, $values): static { return $this->filter(fn ($device) => in_array($device->$column, $values)); }
        public function whereRaw($value): static { return $this->filter(fn () => false); }
        public function get(): \Illuminate\Support\Collection { return collect($this->devices); }
    }
    function __($value): string { return $value; }
    function now(): \Carbon\Carbon { return \Carbon\Carbon::now(); }
    function abort_unless($condition, $status): void { if (! $condition) throw new \RuntimeException((string) $status); }
    function response(): object { return new class { public function json($data): \Illuminate\Http\JsonResponse { return new \Illuminate\Http\JsonResponse($data); } }; }
    function check($condition, $message): void { if (! $condition) throw new \RuntimeException($message); }
    function denied(callable $action, string $status): void
    {
        try { $action(); } catch (\RuntimeException $error) { check($error->getMessage() === $status, 'Unexpected denial: ' . $error->getMessage()); return; }
        throw new \RuntimeException('Expected denial ' . $status);
    }
    $GLOBALS['mapUser'] = new \App\Models\User;
    \Carbon\Carbon::setTestNow('2026-09-25 12:00:00');
    $base = ['name' => 'Router', 'accessible' => true, 'status' => false, 'disabled' => 0, 'disable_notify' => 0, 'type' => 'network', 'groups' => [1, 2], 'maintenance' => false, 'last_polled' => now()->subHour(), 'location' => (object) ['location' => 'Site A', 'lat' => 59.9, 'lng' => 10.7]];
    \App\Models\Device::$fixtures = [
        new \App\Models\Device(['device_id' => 1] + $base),
        new \App\Models\Device(['device_id' => 2, 'location' => null, 'maintenance' => true] + $base),
        new \App\Models\Device(['device_id' => 3, 'accessible' => false] + $base),
        new \App\Models\Device(['device_id' => 4, 'status' => true] + $base),
        new \App\Models\Device(['device_id' => 5, 'disabled' => 1] + $base),
        new \App\Models\Device(['device_id' => 6, 'disable_notify' => 1] + $base),
        new \App\Models\Device(['device_id' => 7, 'groups' => [3]] + $base),
    ];
    \App\Http\Controllers\Widgets\WidgetController::$fixtureSettings = ['device_groups' => [1, 2]];
    $run = fn ($id = 1) => (new \Drakelid\NmsDashWidgets\Http\Controllers\Widgets\OfflineDevicesMapController)->data(new \Illuminate\Http\Request($id));
    $snapshot = $run()->data;
    $devices = (array) $snapshot['devices'];
    check(array_keys($devices) === [1, 2], 'Snapshot must filter access, status, disabled, notify and group scope, without duplicate devices');
    check($devices[1]['outage_seconds'] === 3600, 'Outage estimate must use last successful poll');
    check($devices[1]['site'] === 'Site A' && $devices[1]['last_polled'] !== null, 'Site and source timestamp must be present');
    check($devices[2]['lat'] === null && $devices[2]['maintenance'] === 1, 'Coordinate-less maintenance outage must remain in the snapshot');
    \App\Http\Controllers\Widgets\WidgetController::$fixtureSettings = ['device_groups' => [999]];
    check((array) $run()->data['devices'] === [], 'Inaccessible selection must not broaden to all devices');
    $GLOBALS['mapUser']->dashboardAccess = false;
    denied($run, '403');
    $GLOBALS['mapUser']->dashboardAccess = true;
    $GLOBALS['mapUser']->deviceAccess = false;
    denied($run, '403');
    $GLOBALS['mapUser']->deviceAccess = true;
    \App\Models\UserWidget::$kind = 'some-other-widget';
    denied($run, '404');
    \App\Models\UserWidget::$kind = 'offline-devices-map';
    denied(fn () => $run(2), '404');

    $flap = new \Drakelid\NmsDashWidgets\Http\Controllers\Widgets\FlappingDevicesController;
    $summary = new \ReflectionMethod($flap, 'summary');
    $result = $summary->invoke($flap, collect([
        (object) ['item_type' => 'device', 'changes' => 9, 'last_change' => '2026-09-25 10:00:00'],
        (object) ['item_type' => 'port', 'changes' => 7, 'last_change' => '2026-09-25 11:00:00'],
        (object) ['item_type' => 'port', 'changes' => 4, 'last_change' => '2026-09-25 09:00:00'],
    ]));
    check($result['matched'] === 3 && $result['total_changes'] === 20 && $result['ports'] === 2, 'Flap summary must cover every matching item');
    check($result['last_change'] === '2026-09-25 11:00:00', 'Flap summary must use the newest transition');
    echo "Operations regressions passed: map snapshot/filter/authorization boundaries and full-match flap summary.\n";
}
