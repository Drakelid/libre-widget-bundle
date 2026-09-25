<?php

/**
 * Run health aggregation regressions without a LibreNMS host or database.
 * Only host model/configuration boundaries are stubbed; aggregation, classification,
 * time arithmetic and configuration precedence execute the production methods.
 * Usage: php tests/health-regression.php [source-root]
 * An alternate source root allows checking the same cases against an old revision.
 */
namespace App\Http\Controllers\Widgets {
    class WidgetController
    {
    }
}

namespace App\Models {
    class Sensor
    {
        public function __construct(
            public string $sensor_class,
            public float $sensor_current,
            public ?object $translation = null,
            public string $sensor_descr = 'Test sensor',
            public ?int $sensor_id = null,
            public ?object $device = null,
            public ?string $lastupdate = null,
        ) {
        }

        public function currentTranslation(): ?object
        {
            return $this->translation;
        }
    }

    class PollerCluster
    {
        public static function query(): never
        {
            throw new \RuntimeException('Inventory unavailable');
        }

        public string $poller_name = 'Test poller';
        public string $node_id = 'test';
        public string $poller_version = 'test';

        public function __construct(public mixed $last_report, public ?int $poller_frequency = null, public array $settings = [])
        {
        }

        public function getSettingValue(string $name): mixed
        {
            return $this->settings[$name] ?? match ($name) {
                'poller_groups' => [0],
                'poller_enabled' => true,
                'poller_frequency' => $this->poller_frequency ?? \App\Facades\LibrenmsConfig::$frequency,
            };
        }
    }
}

namespace App\Facades {
    class LibrenmsConfig
    {
        public static int $frequency = 900;

        public static function get(string $key, mixed $default = null): mixed
        {
            return $key === 'service_poller_frequency' ? self::$frequency : $default;
        }
    }
}

namespace {
    use App\Facades\LibrenmsConfig;
    use App\Models\PollerCluster;
    use App\Models\Sensor;
    use Carbon\Carbon;
    use Drakelid\NmsDashWidgets\Http\Controllers\Widgets\PollerHealthController;
    use Drakelid\NmsDashWidgets\Http\Controllers\Widgets\SitePowerStatusController;

    $root = dirname(__DIR__);
    require $root . '/vendor/autoload.php';
    $sourceRoot = $argv[1] ?? $root;
    require $sourceRoot . '/src/Http/Controllers/Widgets/SitePowerStatusController.php';
    require $sourceRoot . '/src/Http/Controllers/Widgets/PollerHealthController.php';

    $failures = 0;
    $checks = 0;
    $check = function (string $case, mixed $expected, mixed $actual) use (&$failures, &$checks): void {
        $checks++;
        if ($expected !== $actual) {
            $failures++;
            printf("[FAIL] %s: expected %s, got %s\n", $case, var_export($expected, true), var_export($actual, true));
        } else {
            printf("[ ok ] %s\n", $case);
        }
    };

    $power = new SitePowerStatusController;
    $apply = new ReflectionMethod($power, 'applySensor');
    $classify = new ReflectionMethod($power, 'classify');
    $settings = ['min_runtime_minutes' => 30, 'min_charge_percent' => 50, 'voltage_low' => 46, 'voltage_high' => 56];
    $summarize = function (array $sensors) use ($power, $apply, $classify, $settings): string {
        $site = [
            'runtime_minutes' => null, 'charge_percent' => null,
            'voltage' => null, 'voltage_max' => null, 'load_watts' => null,
            'states' => [], 'suspect' => 0, 'unknown_state' => false,
        ];
        foreach ($sensors as $sensor) {
            $apply->invokeArgs($power, [&$site, $sensor, $settings]);
        }

        return $classify->invoke($power, $site, $settings);
    };
    $state = fn (int $generic): Sensor => new Sensor('state', 1, (object) [
        'state_generic_value' => $generic, 'state_descr' => 'Reported state',
    ]);

    $check('overvoltage survives a lower healthy reading', 'critical', $summarize([new Sensor('voltage', 48), new Sensor('voltage', 60)]));
    $check('overvoltage is independent of sensor order', 'critical', $summarize([new Sensor('voltage', 60), new Sensor('voltage', 48)]));
    $check('undervoltage remains critical', 'critical', $summarize([new Sensor('voltage', 44), new Sensor('voltage', 52)]));
    $check('healthy battery remains OK', 'ok', $summarize([new Sensor('runtime', 60), new Sensor('charge', 100)]));
    $check('invalid battery runtime is unknown', 'unknown', $summarize([new Sensor('runtime', -8715378)]));
    $check('invalid battery charge is unknown', 'unknown', $summarize([new Sensor('charge', 101)]));
    $check('untranslated state is unknown', 'unknown', $summarize([new Sensor('state', 1)]));
    $check('generic unknown state remains unknown', 'unknown', $summarize([$state(3)]));
    $check('critical battery wins over unknown', 'critical', $summarize([$state(3), new Sensor('runtime', 5)]));
    $check('warning battery wins over invalid data', 'warning', $summarize([new Sensor('runtime', -1), new Sensor('charge', 40)]));

    $site = [
        'runtime_minutes' => null, 'charge_percent' => null, 'voltage' => null, 'voltage_max' => null,
        'load_watts' => null, 'states' => [], 'suspect' => 0, 'unknown_state' => false,
    ];
    $high = new Sensor('voltage', 60, sensor_descr: 'Rectifier B', sensor_id: 2, device: (object) ['device_id' => 20, 'last_polled' => '2026-09-25 11:59:00'], lastupdate: '2025-01-01 00:00:00');
    $low = new Sensor('voltage', 48, sensor_descr: 'Rectifier A', sensor_id: 1, device: (object) ['device_id' => 10, 'last_polled' => '2026-09-25 11:55:00'], lastupdate: '2025-01-01 00:00:00');
    $apply->invokeArgs($power, [&$site, $high, $settings]);
    $apply->invokeArgs($power, [&$site, $low, $settings]);
    $check('maximum voltage retains offending sensor', 2, $site['sources']['voltage_max']['sensor_id']);
    $check('minimum voltage retains its own sensor', 1, $site['sources']['voltage']['sensor_id']);
    $check('alarm source includes offending device', 20, $site['sources']['voltage_max']['device']->device_id);
    $check('site age uses oldest included device poll, not last value change', '2026-09-25 11:55:00', $site['observed_at']);

    Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00', 'UTC'));
    try {
        $poller = new PollerHealthController;
        $active = new ReflectionMethod($poller, 'pollerIsActive');
        LibrenmsConfig::$frequency = 900;
        $check('inherited slow frequency retains active poller', true, $active->invoke($poller, new PollerCluster(Carbon::now()->subSeconds(700))));
        $check('expired inherited slow frequency is stale', false, $active->invoke($poller, new PollerCluster(Carbon::now()->subSeconds(1801))));
        LibrenmsConfig::$frequency = 60;
        $check('inherited fast frequency detects stale poller', false, $active->invoke($poller, new PollerCluster(Carbon::now()->subSeconds(121))));
        $check('explicit node frequency overrides global', true, $active->invoke($poller, new PollerCluster(Carbon::now()->subSeconds(700), 900)));
        $check('never-reported poller is stale', false, $active->invoke($poller, new PollerCluster(null)));
        $summary = new ReflectionMethod($poller, 'pollerSummary');
        $disabled = $summary->invoke($poller, new PollerCluster(null, settings: ['poller_enabled' => false, 'poller_groups' => '2,5']));
        $check('disabled poller remains explicitly disabled', false, $disabled['enabled']);
        $check('node poller group IDs are normalized', [2, 5], $disabled['groups']);
        $check('summary exposes effective inherited interval', 60, $disabled['interval']);
        $inventory = new ReflectionMethod($poller, 'pollers');
        $check('failed inventory is distinct from an empty node list', null, $inventory->invoke($poller));
    } finally {
        Carbon::setTestNow();
    }

    printf("%d checks, %d failures\n", $checks, $failures);
    exit($failures ? 1 : 0);
}
