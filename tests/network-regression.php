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
        public function isUp(): static { return $this; }
        public function has(...$args): static { return $this; }
        public function orderBy(...$args): static { return $this; }
        public function orderByRaw(...$args): static { return $this; }
        public function limit(...$args): static { return $this; }
        public function count(): int { return count($this->records); }
        public function get(): \Illuminate\Support\Collection { return collect($this->records); }
        public function where($column, $operator, $value = null): static
        {
            if (func_num_args() === 2) { $value = $operator; $operator = '='; }
            $column = substr($column, strrpos('.' . $column, '.'));
            $this->records = array_values(array_filter($this->records, fn ($row) => match ($operator) {
                '!=' => ($row->$column ?? null) != $value,
                '>' => ($row->$column ?? null) > $value,
                default => ($row->$column ?? null) == $value,
            }));
            return $this;
        }
        public function whereIn($column, $values): static
        {
            $column = substr($column, strrpos('.' . $column, '.'));
            $this->records = array_values(array_filter($this->records, fn ($row) => in_array($row->$column ?? null, $values, true)));
            return $this;
        }
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
        public static array $fixtures = [];
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
            if (self::$fixtures !== []) {
                return collect(self::$fixtures)->filter(fn ($row) => count(array_filter($this->pairs, fn ($pair) =>
                    $pair['device_id'] === $row->device_id && $pair['bgpPeerIdentifier'] === $row->bgpPeerIdentifier)) > 0);
            }
            return collect($this->pairs)->map(fn ($pair) => (object) ($pair + [
                'context_name' => null,
                'AcceptedPrefixes' => $pair['bgpPeerIdentifier'] === 'peer-501' ? 100 : 1000,
                'AcceptedPrefixes_prev' => 1000,
                'AdvertisedPrefixes' => 1000,
                'PrefixAdminLimit' => 0,
            ]));
        }
    }

    class VrfQuery
    {
        public function whereIntegerInRaw($column, $ids): static { return $this; }
        public function pluck($value, $key): \Illuminate\Support\Collection { return collect([7 => 'blue', 8 => 'red']); }
    }

    \Illuminate\Support\Facades\DB::swap(new class {
        public function table($name): PrefixQuery|VrfQuery { return $name === 'vrfs' ? new VrfQuery : new PrefixQuery; }
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
        public array $configuration = [];
        protected function settings(bool $forSettingsView = false): array
        {
            return $this->normalizeSettings(array_replace($this->defaults, [
                'time_interval' => 0, 'show_admin_down' => true,
            ], $this->configuration));
        }
    }

    class TopUnderTest extends \Drakelid\NmsDashWidgets\Http\Controllers\Widgets\TopBandwidthDeviceGroupController
    {
        public array $configuration = [];
        protected function settings(bool $forSettingsView = false): array
        {
            return $this->normalizeSettings(array_replace($this->defaults, $this->configuration));
        }
    }
    class UplinkUnderTest extends \Drakelid\NmsDashWidgets\Http\Controllers\Widgets\UplinkUtilizationOverviewController
    {
        public array $configuration = [];
        protected function settings(bool $forSettingsView = false): array
        {
            return $this->normalizeSettings(array_replace($this->defaults, $this->configuration));
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
    check(str_contains($data['rows'][0]['reasons'][0], '90%'), 'BGP warning explains the measured prefix loss');
    \App\Models\BgpPeer::$fixtures = [
        (object) ['device_id' => 1, 'bgpPeerIdentifier' => 'p1', 'bgpPeerRemoteAs' => 64500, 'vrf_id' => 7, 'bgpPeerDescr' => 'Transit A', 'bgpPeerAdminStatus' => 'stop', 'bgpPeerState' => 'idle'],
        (object) ['device_id' => 1, 'bgpPeerIdentifier' => 'p2', 'bgpPeerRemoteAs' => 64500, 'vrf_id' => 7, 'bgpPeerDescr' => 'Transit B', 'bgpPeerAdminStatus' => null, 'bgpPeerState' => 'idle'],
        (object) ['device_id' => 1, 'bgpPeerIdentifier' => 'p3', 'bgpPeerRemoteAs' => 64501, 'vrf_id' => 8, 'bgpPeerDescr' => 'Other', 'bgpPeerAdminStatus' => 'stop', 'bgpPeerState' => 'idle'],
    ];
    $bgp->configuration = ['show' => 'all', 'remote_as' => '64500', 'vrf_filter' => '7', 'description_filter' => 'transit'];
    $data = $bgp->getView(new \Illuminate\Http\Request)->payload;
    check($data['summary']['total'] === 2, 'BGP AS, VRF and case-insensitive description filters apply before summaries');
    check($data['summary']['admin_down'] === 1 && $data['summary']['unknown'] === 1, 'BGP shut and unknown sessions are counted separately');
    \App\Models\BgpPeer::$fixtures = array_map(fn ($vrfId) => (object) [
        'device_id' => 1, 'bgpPeerIdentifier' => '192.0.2.1', 'vrf_id' => $vrfId, 'context_name' => '',
        'bgpPeerAdminStatus' => 'start', 'bgpPeerState' => 'established', 'bgpPeerFsmEstablishedTime' => 7200,
    ], [7, 8, 9]);
    PrefixQuery::$fixtures = array_map(fn ($context, $accepted) => (object) [
        'device_id' => 1, 'bgpPeerIdentifier' => '192.0.2.1', 'context_name' => $context,
        'AcceptedPrefixes' => $accepted, 'AcceptedPrefixes_prev' => 1000, 'AdvertisedPrefixes' => 1000, 'PrefixAdminLimit' => 0,
    ], ['blue', 'red', null], [100, 1000, 500]);
    $bgp->configuration = ['show' => 'all'];
    $data = $bgp->getView(new \Illuminate\Http\Request)->payload;
    $byVrf = collect($data['rows'])->keyBy(fn ($row) => $row['peer']->vrf_id);
    check($byVrf[7]['prefix']['accepted'] === 100 && $byVrf[7]['status'] === 'warning', 'VRF blue prefix collapse is isolated to its context');
    check($byVrf[8]['prefix']['accepted'] === 1000 && $byVrf[8]['status'] === 'ok', 'Same peer in VRF red does not inherit another VRF prefix warning');
    check($byVrf[9]['prefix'] === null, 'Unmapped VRF never borrows default-context prefix counts');
    PrefixQuery::$fixtures = [];

    enum AdminStatus: string { case Up = 'up'; case Down = 'down'; }
    foreach ([AdminStatus::Up, 'up', AdminStatus::Down, 'down'] as $index => $status) {
        $port = new \App\Models\Port;
        foreach ([
            'device_id' => 1, 'ifName' => 'customer', 'ifDescr' => '', 'ifAlias' => '',
            'ifAdminStatus' => $status, 'ifOperStatus' => 'down', 'ifLastChange' => null,
            'device' => null,
        ] as $key => $value) { $port->$key = $value; }
        \App\Models\Port::$fixtures = [$port];
        $data = (new CustomerUnderTest)->getView(new \Illuminate\Http\Request)->payload;
        check($data['rows'][0]['admin_down'] === ($index >= 2), 'Customer admin state supports fixture ' . $index);
    }

    $port->device = new class {
        public int $status = 0;
        public int $uptime = 10000;
        public object $location;
        public function __construct() { $this->location = (object) ['location' => 'Site A']; }
        public function displayName(): string { return 'Router A'; }
    };
    $port->ifAlias = 'customer circuit-123';
    $port->ifLastChange = 640000;
    $port->poll_time = time() - 60;
    $customer = new CustomerUnderTest;
    $customer->configuration = ['group_by' => 'site'];
    $data = $customer->getView(new \Illuminate\Http\Request)->payload;
    check($data['rows'][0]['parent_offline'], 'Offline parent is distinguished from a confirmed live interface outage');
    check($data['rows'][0]['outage_group'] === 'Site A', 'Customer grouping uses reported device location');
    check($data['rows'][0]['circuit'] === 'customer circuit-123', 'Circuit identifier preserves reported alias');
    check($data['duration_buckets']['1–24 hours'] === 1, 'Duration bucket uses independently known one-hour outage');

    $port->ifName = 'uplink-1';
    $port->ifSpeed = 1000000000;
    $port->ifInOctets_rate = 100000000; // 800 Mbps
    $port->ifOutOctets_rate = 75000000; // 600 Mbps
    $port->port_id = 1;
    $port->ifAdminStatus = 'up';
    $port->ifOperStatus = 'up';
    $port->ifType = 'ethernetCsmacd';
    $top = new TopUnderTest;
    foreach (['rx' => 800000000.0, 'tx' => 600000000.0, 'combined' => 1400000000.0, 'utilisation' => 80.0] as $sort => $expected) {
        $top->configuration = ['sort_by' => $sort];
        $data = $top->getView(new \Illuminate\Http\Request)->payload;
        check($data['rows'][0]['rank_value'] === $expected, 'Top bandwidth selected metric: ' . $sort);
        check($data['rows'][0]['utilisation'] === 80.0, 'Full duplex utilisation is 80 percent, never combined 140 percent');
    }
    $data = (new UplinkUnderTest)->getView(new \Illuminate\Http\Request)->payload;
    check($data['rows'][0]['remaining_rx'] === \Drakelid\NmsDashWidgets\Support\Format::bits(200000000), 'RX remaining capacity is 200 Mbps');
    check($data['rows'][0]['remaining_tx'] === \Drakelid\NmsDashWidgets\Support\Format::bits(400000000), 'TX remaining capacity is 400 Mbps');
    check($data['rows'][0]['history'] === null, 'Optional historical queries are disabled by default');
    check($data['matched_count'] === 1, 'Uplink matched count precedes display limit');
    $top->configuration = ['interface_scope' => 'aggregate'];
    check($top->getView(new \Illuminate\Http\Request)->payload['matched_total'] === 0, 'Aggregate scope excludes a physical Ethernet interface');
    $port->ifType = 'ieee8023adLag';
    check($top->getView(new \Illuminate\Http\Request)->payload['matched_total'] === 1, 'Aggregate scope selects a reported link aggregate');
    $top->configuration = ['interface_scope' => 'physical'];
    check($top->getView(new \Illuminate\Http\Request)->payload['matched_total'] === 0, 'Physical scope excludes a reported link aggregate');
    $port->ifSpeed = 0;
    $uplink = new UplinkUnderTest;
    $uplink->configuration = ['history_enabled' => true];
    $data = $uplink->getView(new \Illuminate\Http\Request)->payload;
    check($data['rows'][0]['history']['available'] === false && $data['rows'][0]['history']['reason'] !== '', 'Enabled history explains unavailable speed instead of claiming no congestion');
}
