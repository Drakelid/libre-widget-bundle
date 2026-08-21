<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers\Widgets;

use App\Models\Port;
use Drakelid\NmsDashWidgets\Support\BundleWidgetController;
use Drakelid\NmsDashWidgets\Support\Cast;
use Drakelid\NmsDashWidgets\Support\Columns;
use Drakelid\NmsDashWidgets\Support\Presentation;
use Drakelid\NmsDashWidgets\Support\DeviceGroups;
use Drakelid\NmsDashWidgets\Support\SafeRegex;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Customer-facing ports that are administratively up but operationally down.
 *
 * Answers "which customers are down right now", which neither core's top-errors nor
 * top-interfaces addresses. Ports are identified by an ifAlias convention -- the
 * default matches the `Kundeport` tag already used on this network.
 *
 * Down time comes from ports.ifLastChange, an SNMP TimeTicks value (hundredths of a
 * second since the device booted), not a wall-clock timestamp. See downSeconds().
 */
class CustomerPortStatusController extends BundleWidgetController
{
    protected string $name = 'customer-port-status';

    private const CHUNK_SIZE = 1000;

    protected $defaults = [
        'title' => null,
        'device_groups' => [],
        'match_regex' => 'kundeport|customer|kunde',
        'exclude_regex' => '',
        'limit' => 25,
        'time_interval' => 15,
        'min_down_minutes' => 0,
        'show_admin_down' => false,

        // Visible columns. null means "never configured", which falls back to the
        // defaults in Support\Columns (and to any legacy show_* toggles).
        'columns' => null,

        // Layout and styling, shared by every widget in the bundle.
        // PRESENTATION_DEFAULTS -- values come from Presentation::defaults().
        'layout' => 'auto',
        'density' => 'comfortable',
        'accent' => 'default',
        'zebra' => '0',
        'show_header' => '1',
        'card_min_width' => 220,
    ];

    protected function normalizeSettings(array $settings): array
    {
        $settings['title'] = Cast::nullableString($settings['title'] ?? null);
        $settings['device_groups'] = DeviceGroups::ids($settings['device_groups'] ?? []);
        $settings['match_regex'] = trim((string) ($settings['match_regex'] ?? ''));
        $settings['exclude_regex'] = trim((string) ($settings['exclude_regex'] ?? ''));
        $settings['limit'] = Cast::clampedInt($settings['limit'] ?? 25, 1, 200, 25);
        $settings['time_interval'] = Cast::clampedInt($settings['time_interval'] ?? 15, 0, 1440, 15);
        $settings['min_down_minutes'] = Cast::clampedInt($settings['min_down_minutes'] ?? 0, 0, 10080, 0);
        $settings['show_admin_down'] = Cast::bool($settings['show_admin_down'] ?? false, false);

        $settings = Columns::normalize($settings, $this->name);
        $settings = Presentation::normalize($settings, $this->name);

        return $settings;
    }

    public function getView(Request $request): string|View
    {
        $settings = $this->settings();

        // `auto` becomes a concrete layout here, using the widget body size the
        // dashboard posts with every refresh.
        $settings['layout'] = Presentation::resolveLayout($settings, $this->name, $request);
        $settings['widget_classes'] = Presentation::cssClasses($settings, $this->name, $settings['layout']);
        $settings['cols'] = Columns::visible($settings, $this->name);
        $user = $request->user();

        $groupIds = DeviceGroups::accessibleIds($user, $settings['device_groups']);

        $match = SafeRegex::make($settings['match_regex']);
        $exclude = SafeRegex::make($settings['exclude_regex']);

        if ($match->isEmpty() || $match->isInvalid()) {
            $match = SafeRegex::make($this->defaults['match_regex']);
        }

        $query = Port::hasAccess($user)
            // uptime is required by downSeconds(): ifLastChange is relative to it.
            // Leaving it out made every "Down for" cell render as a dash.
            ->with(['device' => fn ($q) => $q->select('device_id', 'hostname', 'sysName', 'status', 'os', 'display', 'uptime')])
            ->isValid()
            ->select([
                'ports.port_id', 'ports.device_id', 'ports.ifName', 'ports.ifDescr',
                'ports.ifAlias', 'ports.ifSpeed', 'ports.ifOperStatus', 'ports.ifAdminStatus',
                'ports.ifLastChange', 'ports.poll_time',
            ])
            ->where('ports.ifOperStatus', 'down')
            ->when(! $settings['show_admin_down'], fn ($q) => $q->where('ports.ifAdminStatus', 'up'))
            ->when(
                $settings['time_interval'] > 0,
                fn ($q) => $q->where('ports.poll_time', '>', Carbon::now()->subMinutes($settings['time_interval'])->timestamp)
            );

        DeviceGroups::scopeToDevices($query, $groupIds);

        $rows = [];
        $matched = 0;
        $minDownSeconds = $settings['min_down_minutes'] * 60;

        $query->chunkById(self::CHUNK_SIZE, function ($ports) use (
            &$rows, &$matched, $match, $exclude, $minDownSeconds
        ): void {
            foreach ($ports as $port) {
                $haystack = trim(implode(' ', array_filter([
                    $port->ifAlias, $port->ifName, $port->ifDescr,
                ])));

                if (! $match->matches($haystack)) {
                    continue;
                }

                if ($exclude->isUsable() && $exclude->matches($haystack)) {
                    continue;
                }

                $matched++;
                $down = $this->downSeconds($port);

                // Filter out brief blips: a port that just bounced is not an outage yet.
                if ($minDownSeconds > 0 && $down !== null && $down < $minDownSeconds) {
                    continue;
                }

                $rows[] = [
                    'port' => $port,
                    'down_seconds' => $down,
                    'admin_down' => strtolower((string) $port->ifAdminStatus) !== 'up',
                ];
            }
        }, 'ports.port_id', 'port_id');

        // Longest outage first: unknown duration sorts last.
        usort($rows, fn (array $a, array $b): int => ($b['down_seconds'] ?? -1) <=> ($a['down_seconds'] ?? -1));

        $total = count($rows);
        $rows = array_slice($rows, 0, $settings['limit']);

        $memberships = DeviceGroups::membershipMap(
            $groupIds,
            array_values(array_unique(array_map(fn (array $r): int => (int) $r['port']->device_id, $rows)))
        );

        foreach ($rows as $i => $row) {
            $rows[$i]['group_names'] = $memberships->get($row['port']->device_id, '');
        }

        return view('widgets.customer-port-status', $settings + $this->shared($settings) + [
            'rows' => $rows,
            'down_total' => $total,
            'matched_total' => $matched,
            'effective_regex' => $match->raw(),
            'group_label' => DeviceGroups::namesFor($user, $groupIds, __('All accessible devices')),
            'regex_problems' => $this->regexProblems($settings, $exclude),
        ]);
    }

    /**
     * How long the port has been in its current state, in seconds.
     *
     * ifLastChange is SNMP TimeTicks: hundredths of a second since the DEVICE booted,
     * not since the epoch. It is therefore only usable as "time since the change
     * relative to device uptime", and resets whenever the device reboots. Returns null
     * when the value is absent or implausible rather than printing a wrong duration.
     */
    private function downSeconds(Port $port): ?int
    {
        $ticks = $port->ifLastChange;

        if (! is_numeric($ticks) || $ticks <= 0) {
            return null;
        }

        $uptimeSeconds = (int) ($port->device?->uptime ?? 0);
        $changedAtSeconds = (int) ((int) $ticks / 100);

        if ($uptimeSeconds <= 0 || $changedAtSeconds > $uptimeSeconds) {
            return null;
        }

        return max(0, $uptimeSeconds - $changedAtSeconds);
    }

    private function regexProblems(array $settings, SafeRegex $exclude): array
    {
        $problems = [];
        $original = SafeRegex::make($settings['match_regex']);

        if ($original->isInvalid()) {
            $problems[] = [
                'label' => __('Customer port regex'),
                'reason' => $original->error() . ' ' . __('The default pattern was used instead.'),
            ];
        }

        if ($exclude->isInvalid() || $exclude->isDegraded()) {
            $problems[] = [
                'label' => __('Exclude regex'),
                'reason' => $exclude->error() . ' ' . __('No ports were excluded.'),
            ];
        }

        return $problems;
    }

    public function getSettingsView(Request $request): View
    {
        $settings = $this->getSettings(true);
        $groupIds = DeviceGroups::ids($settings['device_groups'] ?? []);
        $settings['selected_device_groups'] = DeviceGroups::ordered($request->user(), $groupIds);

        $settings['layouts'] = Presentation::layoutsFor($this->name);
        $settings['column_defs'] = Columns::definitionsFor($this->name);
        $settings['column_visible'] = Columns::visible($settings, $this->name);

        return view('widgets.settings.customer-port-status', $settings);
    }
}
