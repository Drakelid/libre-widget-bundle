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
        @include('widgets.partials.nmsdw-tile', ['value' => $down_total, 'label' => __('Ports down')])
        @include('widgets.partials.nmsdw-tile', ['value' => $matched_total, 'label' => __('Matched down')])
    </div>

    @if(empty($rows))
        @include('widgets.partials.nmsdw-empty', [
            'message' => __('No customer ports are down.'),
            'hint' => __('Ports are matched on ifAlias, ifName and ifDescr. Adjust the regex if your naming convention differs.'),
        ])
    @else
        @if(in_array($layout, ['cards', 'compact', 'tiles'], true))
        {{-- Alternative layouts share one renderer; each widget only supplies records. --}}
        @php
            $records = collect($rows)->map(fn ($r) => [
                'title' => e($r['port']->device?->displayName() ?? __('Unknown device')),
                'subtitle' => $r['port']->ifAlias ?: ($r['port']->ifName ?: $r['port']->ifDescr),
                'value' => $r['admin_down'] ? __('shut') : __('DOWN'),
                'unit' => $r['down_seconds'] !== null
                    ? \Carbon\CarbonInterval::seconds($r['down_seconds'])->cascade()->forHumans(['short' => true, 'parts' => 2])
                    : null,
                'status' => $r['admin_down'] ? 'unknown' : 'critical',
                'meta' => array_values(array_filter([
                    [__('Port'), $r['port']->ifName ?: $r['port']->ifDescr],
                    $r['group_names'] ? [__('Group'), $r['group_names']] : null,
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
                @foreach($rows as $row)
                    @php($port = $row['port'])
                    <tr>
                        <td class="nmsdw-strong">
                            @include('widgets.partials.nmsdw-device-cell', ['linkDevice' => $port->device])
                        </td>
                        <td>
                            <x-port-link :port="$port" />
                            @if($port->ifAlias)
                                <span class="nmsdw-sec">{{ $port->ifAlias }}</span>
                            @endif
                        </td>
                        <td class="nmsdw-nowrap">
                            @include('widgets.partials.nmsdw-pill', [
                                'status' => $row['admin_down'] ? 'unknown' : 'critical',
                                'label' => $row['admin_down'] ? __('shut') : __('DOWN'),
                            ])
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

        @if($down_total > count($rows))
            <div class="nmsdw-note">
                {{ __('Showing :shown of :total matching ports that are down.', ['shown' => count($rows), 'total' => $down_total]) }}
            </div>
        @endif
    @endif
</div>
