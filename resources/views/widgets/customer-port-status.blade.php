@include('widgets.partials.nmsdw-style')

<div class="nmsdw-widget nmsdw-custports">
    <div class="nmsdw-head">{{ __('Customer ports down') }}</div>
    <div class="nmsdw-sub">
        {{ $group_label }} &middot;
        {{ __('matching') }} <code class="nmsdw-code">{{ $effective_regex }}</code>
    </div>

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
        <table class="nmsdw-table">
            <thead>
                <tr>
                    <th>{{ __('Device') }}</th>
                    <th>{{ __('Port') }}</th>
                    <th class="nmsdw-nowrap">{{ __('State') }}</th>
                    <th class="nmsdw-hide-narrow nmsdw-nowrap">{{ __('Down for') }}</th>
                    <th class="nmsdw-hide-narrow">{{ __('Group') }}</th>
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
                        <td class="nmsdw-hide-narrow nmsdw-muted nmsdw-nowrap">
                            @if($row['down_seconds'] !== null)
                                {{ \Carbon\CarbonInterval::seconds($row['down_seconds'])->cascade()->forHumans(['short' => true, 'parts' => 2]) }}
                            @else
                                <span title="{{ __('ifLastChange is relative to device uptime and was unavailable or implausible') }}">&mdash;</span>
                            @endif
                        </td>
                        <td class="nmsdw-hide-narrow nmsdw-muted">{{ $row['group_names'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($down_total > count($rows))
            <div class="nmsdw-note">
                {{ __('Showing :shown of :total matching ports that are down.', ['shown' => count($rows), 'total' => $down_total]) }}
            </div>
        @endif
    @endif
</div>
