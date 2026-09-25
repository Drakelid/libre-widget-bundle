@include('widgets.partials.nmsdw-style')

<div class="{{ $widget_classes }} nmsdw-custports">
    @if($show_header)

        <div class="nmsdw-head">{{ $heading ?: __('Customer ports down') }}</div>
        <div class="nmsdw-sub">
            {{ $group_label }} &middot;
            {{ __('matching') }} <code class="nmsdw-code">{{ $effective_regex }}</code>
        </div>
    @endif

    @include('widgets.partials.nmsdw-regex-warning', ['problems' => $regex_problems])

    <div class="nmsdw-tiles">
        @include('widgets.partials.nmsdw-tile', ['value' => $down_total, 'label' => __('Reported not up')])
        @include('widgets.partials.nmsdw-tile', ['value' => $matched_total, 'label' => __('Before duration filter')])
        @include('widgets.partials.nmsdw-tile', ['value' => $offline_parent_ports, 'label' => __('Ports on offline parents')])
        @foreach($duration_buckets as $bucket => $count)
            @include('widgets.partials.nmsdw-tile', ['value' => $count, 'label' => $bucket])
        @endforeach
    </div>

    @if(empty($rows))
        @include('widgets.partials.nmsdw-empty', [
            'message' => __('No customer ports match the outage, freshness and duration filters.'),
            'hint' => __('Ports are matched on ifAlias, ifName and ifDescr. Adjust the regex if your naming convention differs.'),
        ])
    @else
        @if(in_array($layout, ['cards', 'compact', 'tiles'], true))
        {{-- Alternative layouts share one renderer; each widget only supplies records. --}}
        @php
            $records = collect($rows)->map(fn ($r) => [
                'title' => e($r['port']->device?->displayName() ?? __('Unknown device')),
                'subtitle' => $r['port']->ifAlias ?: ($r['port']->ifName ?: $r['port']->ifDescr),
                'value' => $r['parent_offline'] ? __('Parent offline') : ($r['admin_down'] ? __('shut') : __('Not up')),
                'observed_at' => $r['observed_at'],
                'unit' => $cols['downfor'] && $r['down_seconds'] !== null
                    ? \Carbon\CarbonInterval::seconds($r['down_seconds'])->cascade()->forHumans(['short' => true, 'parts' => 2])
                    : null,
                'status' => $r['parent_offline'] || $r['admin_down'] ? 'unknown' : 'critical',
                'meta' => array_values(array_filter([
                    [__('Port'), $r['port']->ifName ?: $r['port']->ifDescr],
                    $cols['group'] && $r['group_names'] ? [__('Group'), $r['group_names']] : null,
                    $r['outage_group'] ? [__('Outage group'), $r['outage_group']] : null,
                    $r['circuit'] !== '' ? [__('Circuit / alias'), $r['circuit']] : null,
                    $r['parent_offline'] ? [__('Note'), __('Port state is last reported; parent is offline')] : null,
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
                    <th>{{ __('Port') }}</th>
                    <th class="nmsdw-nowrap">{{ __('State') }}</th>
                    @if($cols['downfor'])
                        <th class="nmsdw-hide-narrow nmsdw-nowrap">{{ __('Down for') }}</th>
                    @endif
                    @if($cols['group'])
                        <th class="nmsdw-hide-narrow">{{ __('Group') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @php($previousGroup = null)
                @foreach($rows as $row)
                    @php($port = $row['port'])
                    @if($group_by !== 'none' && $previousGroup !== $row['outage_group'])
                        <tr><th colspan="{{ 3 + (int) $cols['downfor'] + (int) $cols['group'] }}">{{ $row['outage_group'] }} &middot; {{ $group_totals[$row['outage_group']] }} {{ __('matching ports') }}</th></tr>
                        @php($previousGroup = $row['outage_group'])
                    @endif
                    <tr>
                        <td class="nmsdw-strong">
                            @include('widgets.partials.nmsdw-device-cell', ['linkDevice' => $port->device])
                            @include('widgets.partials.nmsdw-data-age', ['timestamp' => $row['observed_at']])
                        </td>
                        <td>
                            <x-port-link :port="$port" />
                            @if($port->ifAlias)
                                <span class="nmsdw-sec">{{ __('Circuit / alias') }}: {{ $row['circuit'] }}</span>
                            @endif
                        </td>
                        <td class="nmsdw-nowrap">
                            @include('widgets.partials.nmsdw-pill', [
                                'status' => $row['parent_offline'] || $row['admin_down'] ? 'unknown' : 'critical',
                                'label' => $row['parent_offline'] ? __('Parent offline') : ($row['admin_down'] ? __('shut') : __('Not up')),
                            ])
                            @if($row['parent_offline'])<span class="nmsdw-sec">{{ __('Port state is last reported; parent is offline') }}</span>@endif
                        </td>
                        @if($cols['downfor'])
                            <td class="nmsdw-hide-narrow nmsdw-muted nmsdw-nowrap">
                                @if($row['down_seconds'] !== null)
                                    {{ \Carbon\CarbonInterval::seconds($row['down_seconds'])->cascade()->forHumans(['short' => true, 'parts' => 2]) }}
                                @else
                                    <span title="{{ __('ifLastChange is relative to device uptime and was unavailable or implausible') }}">&mdash;</span>
                                @endif
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
    @include('widgets.partials.nmsdw-result-count', ['shown' => count($rows), 'matched' => $down_total, 'noun' => __('ports')])
    <div class="nmsdw-note">{{ __('Counts describe matching ports, not unique customers. Durations are relative to the last device sample; offline parents have no live port confirmation.') }}</div>
</div>
