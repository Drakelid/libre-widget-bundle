@include('widgets.partials.nmsdw-style')

<div class="nmsdw-widget nmsdw-uplink">
    <div class="nmsdw-head">{{ __('Uplink Utilization Overview') }}</div>
    <div class="nmsdw-sub">
        {{ __('uplink regex') }}: <code class="nmsdw-code">{{ $effective_regex }}</code>
        &middot; {{ __('last :count minutes', ['count' => $time_interval]) }}
        @if($group_label)
            &middot; {{ $group_label }}
        @endif
    </div>

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
        <table class="nmsdw-table">
            <thead>
                <tr>
                    <th>{{ __('Device') }}</th>
                    <th>{{ __('Uplink interface') }}</th>
                    <th>{{ __('Utilization') }}</th>
                    <th class="nmsdw-hide-narrow">{{ __('Traffic') }}</th>
                    <th class="nmsdw-hide-narrow nmsdw-nowrap">{{ __('Speed') }}</th>
                    @if($show_graphs)
                        <th class="nmsdw-hide-narrow">{{ __('Graph') }}</th>
                    @endif
                    @if($show_device_group)
                        <th class="nmsdw-hide-narrow">{{ __('Group') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    @php($port = $row['port'])
                    <tr>
                        <td class="nmsdw-strong">
                            <x-device-link :device="$port->device" />
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
                            @include('widgets.partials.nmsdw-meter', [
                                'percent' => $row['utilisation'] ?? 0,
                                'status' => $row['status'],
                            ])
                            <div class="nmsdw-sec">
                                {{ __('Warning') }} {{ $warning_threshold }}% &middot;
                                {{ __('Critical') }} {{ $critical_threshold }}%
                            </div>
                            <span class="nmsdw-sec nmsdw-show-narrow">
                                RX: {{ $row['in_label'] }} &middot; TX: {{ $row['out_label'] }}
                            </span>
                        </td>
                        <td class="nmsdw-hide-narrow nmsdw-muted nmsdw-nowrap">
                            <div>RX: {{ $row['in_label'] }}</div>
                            <div>TX: {{ $row['out_label'] }}</div>
                            <div>{{ __('Total') }}: {{ $row['total_label'] }}</div>
                        </td>
                        <td class="nmsdw-hide-narrow nmsdw-nowrap">{{ $row['speed_label'] }}</td>
                        @if($show_graphs)
                            <td class="nmsdw-hide-narrow nmsdw-graph">
                                <x-port-link :port="$port">
                                    <x-graph :port="$port" type="port_bits" width="150" height="30" legend="no" />
                                </x-port-link>
                            </td>
                        @endif
                        @if($show_device_group)
                            <td class="nmsdw-hide-narrow nmsdw-muted">{{ $row['group_names'] }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($summary['matched'] > count($rows))
            <div class="nmsdw-note">
                {{ __('Showing the :shown highest of :matched matched uplinks. Summary figures above cover all matches.', [
                    'shown' => count($rows),
                    'matched' => $summary['matched'],
                ]) }}
            </div>
        @endif
    @endif
</div>
