<?php

/**
 * Standalone regressions for cached settings and optical attachment.
 * Minimal host doubles supply LibreNMS's settings cache and model reads; the
 * plugin methods and Illuminate collections are real. This does not test SQL.
 * Run: php tests/widget-regression.php [source-root]
 */

namespace App\Http\Controllers\Widgets {
    class WidgetController
    {
        public static array $saved = [];
        protected $defaults = [];
        protected $settings = null;

        public function getSettings($settingsView = false): array
        {
            if ($this->settings === null) {
                $this->settings = array_replace($this->defaults, self::$saved);
                if ($settingsView && ! empty($this->settings['device_group'])) {
                    $this->settings['device_group'] = \App\Models\DeviceGroup::find($this->settings['device_group']);
                }
            }

            return $this->settings;
        }
    }
}

namespace App\Models {
    class User {}

    class FixtureQuery
    {
        public function __construct(private array $rows) {}

        public function whereIntegerInRaw($key, $values): self
        {
            $this->rows = array_filter($this->rows, fn ($row) => in_array((int) $row->$key, $values, true));

            return $this;
        }

        public function where($key, $value): self
        {
            $this->rows = array_filter($this->rows, fn ($row) => $row->$key == $value);

            return $this;
        }

        public function hasAccess($user): self
        {
            $this->rows = array_filter($this->rows, fn ($row) => $row->allowed ?? true);

            return $this;
        }

        public function get() { return collect(array_values($this->rows)); }
        public function pluck($key) { return $this->get()->pluck($key); }
    }

    class DeviceGroup
    {
        public static array $records = [];
        public function __construct(public int $id, public string $name, public bool $allowed = true) {}
        public static function query() { return new FixtureQuery(self::$records); }
        public static function find($id) { return collect(self::$records)->firstWhere('id', $id); }
    }

    class Port
    {
        public static array $records = [];
        public int $deleted = 0;
        public int $disabled = 0;
        public string $ifAdminStatus = 'up';
        public string $ifOperStatus = 'up';
        public function __construct(public int $device_id, public int $port_id, public int $ifIndex) {}
        public static function query() { return new FixtureQuery(self::$records); }
    }

    class Transceiver
    {
        public static array $records = [];
        public function __construct(public int $device_id, public int $port_id, public ?int $entity_physical_index) {}
        public static function query() { return new FixtureQuery(self::$records); }
    }
}

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $sourceRoot = $argv[1] ?? dirname(__DIR__);
    require $sourceRoot . '/src/Support/BundleWidgetController.php';
    foreach (glob($sourceRoot . '/src/Http/Controllers/Widgets/*Controller.php') as $controllerFile) {
        require $controllerFile;
    }

    use App\Http\Controllers\Widgets\WidgetController;
    use App\Models\DeviceGroup;
    use App\Models\Port;
    use App\Models\Transceiver;
    use Drakelid\NmsDashWidgets\Http\Controllers\Widgets\FlappingDevicesController;
    use Drakelid\NmsDashWidgets\Http\Controllers\Widgets\OpticalLightLevelsController;
    use Drakelid\NmsDashWidgets\Support\Presentation;
    use Drakelid\NmsDashWidgets\Support\WidgetCatalog;
    use Illuminate\Support\Facades\Auth;

    function __($message) { return $message; }
    function check($condition, string $message): void
    {
        if (! $condition) {
            throw new \RuntimeException($message);
        }
    }

    Auth::swap(new class {
        public function user() { return new \App\Models\User; }
    });

    foreach (array_keys(Presentation::LAYOUTS) as $slug) {
        $class = 'Drakelid\\NmsDashWidgets\\Http\\Controllers\\Widgets\\' . WidgetCatalog::controller($slug);
        $controller = new $class;
        $controller->getTitle(); // Real core invokes this before opening settings.
        $settings = $controller->getSettings(true);
        check(array_key_exists('heading', $settings) && $settings['heading'] === null, "$slug missing default heading");
    }

    DeviceGroup::$records = [new DeviceGroup(7, 'Access'), new DeviceGroup(8, 'Restricted', false)];
    WidgetController::$saved = ['device_group' => 7, 'heading' => 'Custom heading'];
    $controller = new FlappingDevicesController;
    $controller->getTitle();
    check($controller->getSettings(true)['device_group'] instanceof DeviceGroup, 'Cached scalar group was not resolved for settings');
    check($controller->getSettings(false)['device_group'] === 7, 'Settings rendering changed cached scalar group');
    check($controller->getSettings(true)['heading'] === 'Custom heading', 'Custom heading lost');

    foreach ([8, 999] as $groupId) {
        WidgetController::$saved = ['device_group' => $groupId];
        $controller = new FlappingDevicesController;
        $controller->getTitle();
        check($controller->getSettings(true)['device_group'] === null, 'Restricted/deleted group survived access guard');
    }

    Port::$records = [new Port(1, 11, 7), new Port(1, 12, 8), new Port(2, 21, 7)];
    Transceiver::$records = [
        new Transceiver(1, 11, null), // Direct mapping works without an entity index.
        new Transceiver(1, 12, 7), // Collides with port 11's ifIndex.
        new Transceiver(2, 21, 7), // Same index on another device.
    ];
    $makeRow = fn ($device, $index, $measured) => ['sensor' => (object) [
        'device_id' => $device, 'entPhysicalIndex' => $index, 'entPhysicalIndex_measured' => $measured,
    ]];
    $rows = [$makeRow(1, 7, 'ports'), $makeRow(1, 7, 'port'), $makeRow(1, 7, null), $makeRow(2, 7, 'ports'), $makeRow(1, null, null)];
    $controller = new OpticalLightLevelsController;
    $attach = new \ReflectionMethod($controller, 'attachPorts');
    $result = $attach->invoke($controller, $rows, true);
    foreach ([11, 11, 12, 21, null] as $i => $portId) {
        check(($result[$i]['port']?->port_id) === $portId, "Wrong optical port for row $i");
        check(($result[$i]['transceiver']?->port_id) === $portId, "Wrong optical transceiver for row $i");
    }
    $result = $attach->invoke($controller, $rows, false);
    check($result[0]['transceiver'] === null && $result[0]['port']->port_id === 11, 'Hiding optic details changed port mapping');
    echo "PASS: all 10 settings defaults, cached group resolution/access, optical index collisions and hidden details\n";
}
