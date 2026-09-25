<?php

namespace Illuminate\Http {
    // Host validation boundary; validation rules themselves are checked separately.
    class Request {
        public function __construct(public array $data) {}
        public function validate(array $rules): array { return array_intersect_key($this->data, $rules); }
    }
}

namespace {
    require __DIR__ . '/widget-regression.php';

    function abort_if($condition, $status) {
        if ($condition) { throw new RuntimeException('HTTP ' . $status); }
    }
    class PreviewFixture extends \Drakelid\NmsDashWidgets\Support\BundleWidgetController {
        protected string $name = 'top-device-temperatures';
        protected $defaults = ['device_count' => 20, 'show_history' => true, 'history_enabled' => true];
        public array $used = [];
        public function getView($request) {
            $this->used = $this->getSettings();
            $device = new class { public function displayName() { return 'router'; } };
            $rows = array_fill(0, 9, ['sensor' => (object) ['device' => $device, 'sensor_descr' => 'Chassis']]);
            return new class($rows) {
                public function __construct(private array $rows) {}
                public function getData() { return ['rows' => $this->rows, 'matched_count' => 73]; }
            };
        }
    }
    \App\Http\Controllers\Widgets\WidgetController::$saved = ['sensor_include_regex' => 'saved', 'device_count' => 99];
    $controller = new PreviewFixture();
    $result = $controller->regexPreview(new \Illuminate\Http\Request(['sensor_include_regex' => 'chassis', 'device_groups' => [7]]));
    check($result['matched'] === 73, 'Preview count was truncated');
    check(count($result['examples']) === 5 && $result['examples'][0] === 'router Chassis', 'Preview examples are missing or unbounded');
    check($controller->used['sensor_include_regex'] === 'chassis', 'Preview ignored unsaved pattern');
    check($controller->used['show_history'] === false && $controller->used['history_enabled'] === false, 'Preview fetched history');
    check($controller->used['device_count'] === 5, 'Preview did not limit displayed examples');
    check(\App\Http\Controllers\Widgets\WidgetController::$saved['sensor_include_regex'] === 'saved', 'Preview persisted settings');
    $controller = new PreviewFixture();
    $result = $controller->regexPreview(new \Illuminate\Http\Request(['sensor_include_regex' => '[']));
    check(isset($result['error']) && $controller->used === [], 'Invalid regex executed query');
    $controller = new PreviewFixture();
    $result = $controller->regexPreview(new \Illuminate\Http\Request(['device_groups' => [8]]));
    check($result['matched'] === 0 && $controller->used === [], 'Inaccessible group widened preview scope');
    echo "PASS: preview uses unsaved filters without saving, rejects invalid/inaccessible filters, retains full count and bounds examples/history\n";
}
