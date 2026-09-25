<?php

/**
 * Controller regressions without a LibreNMS database.
 * Run separately: php tests/network-regression.php
 * Only host/model/query boundaries are doubled; the real controllers build rows.
 */
namespace Illuminate\Database\Eloquent {
    class Builder
    {
        public function __construct(private array $records = []) {}
        public function with(...$args): static { return $this; }
        public function select(...$args): static { return $this; }
        public function isValid(): static { return $this; }
        public function where(...$args): static { return $this; }
        public function when($condition, $callback): static
        {
            if ($condition) { $callback($this); }
            return $this;
        }
        public function chunkById($size, $callback, ...$args): void
        {
            foreach (array_chunk($this->records, $size) as $chunk) { $callback(collect($chunk)); }
        }
    }
}

namespace App\Http\Controllers\Widgets {
    class WidgetController {}
}

namespace Illuminate\Http {
    class Request
    {
        public function user(): \App\Models\User { return new \App\Models\User; }
        public function input($key): mixed { return null; }
    }
}

namespace App\Models {
    class User {}
    class BgpPeer
    {
        public static array $fixtures = [];
        public static function hasAccess($user): \Illuminate\Database\Eloquent\Builder
        {
            return new \Illuminate\Database\Eloquent\Builder(self::$fixtures);
        }
    }
    #[\AllowDynamicProperties]
    class Port
    {
        public static array $fixtures = [];
        public static function hasAccess($user): \Illuminate\Database\Eloquent\Builder
        {
            return new \Illuminate\Database\Eloquent\Builder(self::$fixtures);
        }
    }
    class DeviceGroup
    {
        public static function query(): static { return new static; }
        public function hasAccess($user): static { return $this; }
        public function orderBy($column): static { return $this; }
        public function get($columns): \Illuminate\Support\Collection { return collect(); }
    }
}

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';

    function __($text, $parameters = []): string { return $text; }
    function view($name, $data): RegressionView { return new RegressionView($data); }

    class RegressionView extends \Illuminate\View\View
    {
        public function __construct(public array $payload) {}
    }

    // A tiny predicate-aware database double for the prefix query. It deliberately
    // returns only identities requested by the controller, including across chunks.
    class PrefixQuery
    {
        public array $pairs = [];
        public array $criteria = [];
        public static array $batchSizes = [];
        public function select(...$columns): static { return $this; }
        public function where($column, $value = null): static
        {
            if (is_callable($column)) { $column($this); }
            else { $this->criteria[$column] = $value; }
            return $this;
        }
        public function orWhere($callback): static
        {
            $pair = new self;
            $callback($pair);
            $this->pairs[] = $pair->criteria;
            return $this;
        }
        public function get(): \Illuminate\Support\Collection
        {
            self::$batchSizes[] = count($this->pairs);
            return collect($this->pairs)->map(fn ($pair) => (object) ($pair + [
                'AcceptedPrefixes' => $pair['bgpPeerIdentifier'] === 'peer-501' ? 100 : 1000,
                'AcceptedPrefixes_prev' => 1000,
                'AdvertisedPrefixes' => 1000,
                'PrefixAdminLimit' => 0,
            ]));
        }
    }

    \Illuminate\Support\Facades\DB::swap(new class {
        public function table($name): PrefixQuery { return new PrefixQuery; }
    });

    class BgpUnderTest extends \Drakelid\NmsDashWidgets\Http\Controllers\Widgets\BgpSessionHealthController
    {
        public array $configuration = [];
        protected function settings(bool $forSettingsView = false): array
        {
            return $this->normalizeSettings(array_replace($this->defaults, $this->configuration));
        }
    }

    class CustomerUnderTest extends \Drakelid\NmsDashWidgets\Http\Controllers\Widgets\CustomerPortStatusController
    {
        protected function settings(bool $forSettingsView = false): array
        {
            return $this->normalizeSettings(array_replace($this->defaults, [
                'time_interval' => 0, 'show_admin_down' => true,
            ]));
        }
    }

    function check(bool $condition, string $message): void
    {
        if (! $condition) { throw new \RuntimeException($message); }
        echo "[ok] $message\n";
    }

    for ($i = 1; $i <= 501; $i++) {
        \App\Models\BgpPeer::$fixtures[] = (object) [
            'device_id' => 1, 'bgpPeer_id' => $i, 'bgpPeerIdentifier' => "peer-$i",
            'bgpPeerAdminStatus' => 'start', 'bgpPeerState' => 'established',
            'bgpPeerFsmEstablishedTime' => 7200 + $i,
        ];
    }
    $bgp = new BgpUnderTest;
    $bgp->configuration = ['limit' => 1, 'show' => 'problems'];
    $data = $bgp->getView(new \Illuminate\Http\Request)->payload;
    check(count($data['rows']) === 1, 'Problems includes a stable session with prefix loss');
    check($data['rows'][0]['peer']->bgpPeerIdentifier === 'peer-501', 'Prefix loss beyond the first 500 peers survives limit 1');
    check($data['rows'][0]['status'] === 'warning', '90 percent prefix loss is a warning');
    check(PrefixQuery::$batchSizes === [500, 1], 'Prefix lookups stay bounded to each peer chunk');
    $bgp->configuration['show'] = 'all';
    $data = $bgp->getView(new \Illuminate\Http\Request)->payload;
    check($data['rows'][0]['peer']->bgpPeerIdentifier === 'peer-501', 'Prefix warning outranks healthy peers in All mode');

    enum AdminStatus: string { case Up = 'up'; case Down = 'down'; }
    foreach ([AdminStatus::Up, 'up', AdminStatus::Down, 'down'] as $index => $status) {
        $port = new \App\Models\Port;
        foreach ([
            'device_id' => 1, 'ifName' => 'customer', 'ifDescr' => '', 'ifAlias' => '',
            'ifAdminStatus' => $status, 'ifOperStatus' => 'down', 'ifLastChange' => null,
        ] as $key => $value) { $port->$key = $value; }
        \App\Models\Port::$fixtures = [$port];
        $data = (new CustomerUnderTest)->getView(new \Illuminate\Http\Request)->payload;
        check($data['rows'][0]['admin_down'] === ($index >= 2), 'Customer admin state supports fixture ' . $index);
    }
}
