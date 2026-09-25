@include('widgets.partials.nmsdw-style')

<div class="{{ $widget_classes }} nmsdw-uplink">
    @if($show_header)

        <div class="nmsdw-head">{{ $heading ?: __('Uplink Utilization Overview') }}</div>
        <div class="nmsdw-sub">
            {{ __('uplink regex') }}: <code class="nmsdw-code">{{ $effective_regex }}</code>
            &middot; {{ __('last :count minutes', ['count' => $time_interval]) }}
            @if($group_label)
                &middot; {{ $group_label }}
            @endif
        </div>
    @endif

    @include('widgets.partials.nmsdw-regex-warning', ['problems' => $regex_problems])

    <div class="nmsdw-tiles">
        @include('widgets.partials.nmsdw-tile', [
            'value' => $summary['matched'],
            'label' => __('Matched uplinks'),
        ])
        @include('widgets.partials.nmsdw-tile', [
            'value' => $summary['max_utilisation_label'],
            'label' => __('Highest utilization'),
        ])
        @include('widgets.partials.nmsdw-tile', [
            'value' => $summary['avg_utilisation_label'],
            'label' => __('Average utilization'),
        ])
        @include('widgets.partials.nmsdw-tile', [
            'value' => $summary['total_in_label'],
            'label' => __('Total inbound'),
        ])
        @include('widgets.partials.nmsdw-tile', [
            'value' => $summary['total_out_label'],
            'label' => __('Total outbound'),
        ])
        <div class="nmsdw-tile">
            <div class="nmsdw-tile-counts">
                <span class="nmsdw-pill nmsdw-pill-critical">{{ $summary['critical_count'] }}</span>
                <span class="nmsdw-pill nmsdw-pill-warning">{{ $summary['warning_count'] }}</span>
                <span class="nmsdw-pill nmsdw-pill-ok">{{ $summary['ok_count'] }}</span>
                @if($summary['unknown_count'] > 0)
                    <span class="nmsdw-pill nmsdw-pill-unknown">{{ $summary['unknown_count'] }}</span>
                @endif
            </div>
            <div class="nmsdw-tile-label">
                {{ __('Critical / warning / ok') }}@if($summary['unknown_count'] > 0) / {{ __('unknown') }}@endif
            </div>
        </div>
    </div>

    @if(empty($rows))
        @include('widgets.partials.nmsdw-empty', [
            'message' => __('No uplinks matched.'),
            'hint' => __('Try broadening the uplink regex. Current pattern: :pattern', ['pattern' => $effective_regex]),
        ])
    @else
        @if(in_array($layout, ['cards', 'compact', 'tiles'], true))
        {{-- Alternative layouts share one renderer; each widget only supplies records. --}}
        @php
            $records = collect($rows)->map(fn ($r) => [
                'title' => e($r['port']->device?->displayName() ?? __('Unknown device')),
                'subtitle' => $r['port']->ifName ?: $r['port']->ifDescr,
                'value' => $r['utilisation_label'],
                'unit' => __('peak :v', ['v' => $r['peak_label']]),
                'status' => $r['status'],
                'observed_at' => $r['observed_at'],
                'graph' => $cols['graph'] ? view('widgets.partials.nmsdw-network-graph', ['port' => $r['port']])->render() : null,
                'bar' => $r['utilisation'] ?? 0,
                'meta' => array_values(array_filter([
                    $cols['traffic'] ? ['RX', $r['in_label']] : null,
                    $cols['traffic'] ? ['TX', $r['out_label']] : null,
                    $cols['speed'] ? [__('Speed'), $r['speed_label']] : null,
                    [__('Remaining RX / TX'), $r['remaining_rx'] . ' / ' . $r['remaining_tx']],
                    [__('RX / TX utilisation'), $r['rx_utilisation'] . ' / ' . $r['tx_utilisation']],
                    $cols['group'] ? [__('Group'), $r['group_names']] : null,
                    $r['history'] ? [__('History'), $r['history']['available'] ? ($r['history']['sustained'] ? __('Sustained congestion') : __('Not sustained')) . ' · ' . $r['history']['minutes'] . ' min · ' . count($r['history']['points']) . ' samples' : __('Unavailable') . ': ' . ($r['history']['reason'] ?? '')] : null,
                ])),
                'href' => \LibreNMS\Util\Url::portUrl($r['port']),
            ])->all();
        @endphp

        @include('widgets.partials.nmsdw-records', [
            'records' => $records,
            'layout' => $layout,
            'card_min_width' => $card_min_width,
        ])
    @else
        <table class="nmsdw-table">
            <thead>
                <tr>
                    <th>{{ __('Device') }}</th>
                    <th>{{ __('Uplink interface') }}</th>
                    <th>{{ __('Utilization') }}</th>
                    @if($cols['traffic'])
                        <th class="nmsdw-hide-narrow">{{ __('Traffic') }}</th>
                    @endif
                    @if($cols['speed'])
                        <th class="nmsdw-hide-narrow nmsdw-nowrap">{{ __('Speed') }}</th>
                    @endif
                    @if($cols['graph'])
                        <th class="nmsdw-hide-narrow">{{ __('Graph') }}</th>
                    @endif
                    @if($cols['group'])
                        <th class="nmsdw-hide-narrow">{{ __('Group') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    @php($port = $row['port'])
                    <tr>
                        <td class="nmsdw-strong">
                            @include('widgets.partials.nmsdw-device-cell', ['linkDevice' => $port->device])
                            @include('widgets.partials.nmsdw-data-age', ['timestamp' => $row['observed_at']])
                        </td>
                        <td>
                            <x-port-link :port="$port">
                                <span class="nmsdw-strong">{{ $port->ifName ?: $port->ifDescr }}</span>
                            </x-port-link>
                            @if($port->ifAlias)
                                <span class="nmsdw-sec">{{ $port->ifAlias }}</span>
                            @endif
                        </td>
                        <td>
                            @include('widgets.partials.nmsdw-pill', [
                                'status' => $row['status'],
                                'label' => $row['utilisation_label'],
                            ])
                            <div class="nmsdw-sec">{{ __('peak') }} {{ $row['peak_label'] }}</div>
                            <div class="nmsdw-sec">RX {{ $row['rx_utilisation'] }} / TX {{ $row['tx_utilisation'] }}</div>
                            <div class="nmsdw-sec">{{ __('Remaining RX / TX') }}: {{ $row['remaining_rx'] }} / {{ $row['remaining_tx'] }}</div>
                            @if($row['history'])
                                <div class="nmsdw-sec">
                                    {{ __('History') }}:
                                    @if($row['history']['available'])
                                        {{ $row['history']['sustained'] ? __('Sustained congestion') : __('Not sustained') }}
                                        &middot; {{ $row['history']['minutes'] }} min &middot; {{ count($row['history']['points']) }} {{ __('samples') }}
                                    @else
                                        {{ __('Unavailable') }}: {{ $row['history']['reason'] ?? '' }}
                                    @endif
                                </div>
                            @endif
                            @include('widgets.partials.nmsdw-meter', [
                                'percent' => $row['utilisation'] ?? 0,
                                'status' => $row['status'],
                            ])
                            <div class="nmsdw-sec">
                                {{ __('Warning') }} {{ $warning_threshold }}% &middot;
                                {{ __('Critical') }} {{ $critical_threshold }}%
                            </div>
                            @if($cols['traffic'])<span class="nmsdw-sec nmsdw-show-narrow">
                                RX: {{ $row['in_label'] }} &middot; TX: {{ $row['out_label'] }}
                            </span>@endif
                        </td>
                        @if($cols['traffic'])
                            <td class="nmsdw-hide-narrow nmsdw-muted nmsdw-nowrap">
                                <div>RX: {{ $row['in_label'] }}</div>
                                <div>TX: {{ $row['out_label'] }}</div>
                                <div>{{ __('Total') }}: {{ $row['total_label'] }}</div>
                            </td>
                        @endif
                        @if($cols['speed'])
                            <td class="nmsdw-hide-narrow nmsdw-nowrap">{{ $row['speed_label'] }}</td>
                        @endif
                        @if($cols['graph'])
                            <td class="nmsdw-hide-narrow nmsdw-graph">
                                <x-port-link :port="$port">
                                    {{-- :link="false" is required: x-graph otherwise renders its own <a>, --}}
                                    {{-- which would nest inside x-port-link's anchor. --}}
                                    <x-graph :port="$port" type="port_bits" :width="150" :height="30"
                                             legend="no" :link="false" />
                                </x-port-link>
                            </td>
                        @endif
                        @if($cols['group'])
                            <td class="nmsdw-hide-narrow nmsdw-muted">{{ $row['group_names'] }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @endif
    @include('widgets.partials.nmsdw-result-count', ['shown' => count($rows), 'matched' => $summary['matched'], 'noun' => __('uplinks')])
    @if($history_enabled)<div class="nmsdw-note">{{ __('History covers displayed uplinks only. Summary figures use current samples across every match.') }}</div>@endif
</div>
