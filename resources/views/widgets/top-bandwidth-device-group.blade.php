@include('widgets.partials.nmsdw-style')

<div class="{{ $widget_classes }} nmsdw-bandwidth">
    @if($show_header)

        <div class="nmsdw-head">{{ $heading ?: __('Top :count bandwidth ports', ['count' => $top_count]) }}</div>
        <div class="nmsdw-sub">
            {{ $group_label }} &middot; {{ __('polled within :count minutes', ['count' => $time_interval]) }}
            &middot; {{ $sort_label }}
        </div>
    @endif

    @if(empty($rows))
        @include('widgets.partials.nmsdw-empty', [
            'message' => __('No ports matched.'),
            'hint' => __('Nothing in :groups has been polled in the last :count minutes.', [
                'groups' => $group_label,
                'count' => $time_interval,
            ]),
        ])
    @else
        @if(in_array($layout, ['cards', 'compact', 'tiles'], true))
        {{-- Alternative layouts share one renderer; each widget only supplies records. --}}
        @php
            $records = collect($rows)->map(fn ($r) => [
                'title' => e($r['port']->device?->displayName() ?? __('Unknown device')),
                'subtitle' => $r['port']->ifName ?: $r['port']->ifDescr,
                'value' => $r['rank_label'],
                'unit' => $sort_label,
                'observed_at' => $r['observed_at'],
                'graph' => $cols['graph'] ? view('widgets.partials.nmsdw-network-graph', ['port' => $r['port']])->render() : null,
                'status' => 'info',
                'bar' => $r['bar_percent'],
                'meta' => array_values(array_filter([
                    $cols['inout'] ? [__('RX'), $r['in_label']] : null,
                    $cols['inout'] ? [__('TX'), $r['out_label']] : null,
                    $cols['utilisation'] ? [__('Util'), $r['utilisation_label']] : null,
                    $cols['utilisation'] ? [__('RX / TX utilisation'), $r['rx_utilisation'] . ' / ' . $r['tx_utilisation']] : null,
                    $cols['group'] ? [__('Group'), $r['group_names']] : null,
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
                    <th>{{ __('Interface') }}</th>
                    <th>{{ $sort_label }}</th>
                    @if($cols['inout'])
                        <th class="nmsdw-hide-narrow">{{ __('In / Out') }}</th>
                    @endif
                    @if($cols['utilisation'])
                        <th class="nmsdw-nowrap">{{ __('Util.') }}</th>
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
                        <td class="nmsdw-nowrap">
                            <span class="nmsdw-strong">{{ $row['rank_label'] }}</span>
                            @include('widgets.partials.nmsdw-meter', [
                                'percent' => $row['bar_percent'],
                                'status' => 'info',
                            ])
                            {{-- On narrow widgets the In/Out column is hidden, so fold it in here. --}}
                            @if($cols['inout'])<span class="nmsdw-sec nmsdw-show-narrow">
                                {{ __('In') }}: {{ $row['in_label'] }} &middot;
                                {{ __('Out') }}: {{ $row['out_label'] }}
                            </span>@endif
                        </td>
                        @if($cols['inout'])
                            <td class="nmsdw-hide-narrow nmsdw-muted nmsdw-nowrap">
                                <div>{{ __('In') }}: {{ $row['in_label'] }}</div>
                                <div>{{ __('Out') }}: {{ $row['out_label'] }}</div>
                            </td>
                        @endif
                        @if($cols['utilisation'])
                            <td class="nmsdw-nowrap">{{ $row['utilisation_label'] }}
                                <span class="nmsdw-sec">RX {{ $row['rx_utilisation'] }} / TX {{ $row['tx_utilisation'] }}</span>
                            </td>
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
    @include('widgets.partials.nmsdw-result-count', ['shown' => count($rows), 'matched' => $matched_total, 'noun' => __('ports')])
</div>
