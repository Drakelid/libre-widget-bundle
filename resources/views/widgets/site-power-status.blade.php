@include('widgets.partials.nmsdw-style')

<div class="nmsdw-widget nmsdw-power">
    <div class="nmsdw-head">{{ __('Site power and battery') }}</div>
    <div class="nmsdw-sub">
        {{ $group_label }} &middot;
        {{ $group_by === 'location' ? __('grouped by location') : __('grouped by device') }} &middot;
        {{ __(':count monitored', ['count' => $site_count]) }}
    </div>

    @if(empty($rows))
        @include('widgets.partials.nmsdw-empty', [
            'message' => $site_count === 0
                ? __('No power or battery sensors found.')
                : __('All sites are on mains with healthy reserve.'),
            'hint' => $site_count === 0
                ? __('This widget reads charge, runtime, voltage, current, power and state sensors. UPS or rectifier support may not be discovered on these devices.')
                : null,
        ])
    @else
        @foreach($rows as $row)
            <div class="nmsdw-temp-row nmsdw-temp-{{ $row['status'] }}">
                <div class="nmsdw-temp-name">
                    @if($group_by === 'location')
                        {{ $row['label'] }}
                        <span class="nmsdw-sec">{{ __(':count devices', ['count' => $row['device_count']]) }}</span>
                    @else
                        @include('widgets.partials.nmsdw-device-cell', ['linkDevice' => $row['device']])
                        @if(!empty($row['states']))
                            <span class="nmsdw-sec">{{ $row['states'][0]['text'] }}</span>
                        @endif
                    @endif
                </div>

                <div class="nmsdw-temp-value">
                    @if($row['runtime_label'])
                        {{ $row['runtime_label'] }}
                    @elseif($row['charge_label'])
                        {{ $row['charge_label'] }}
                    @else
                        &mdash;
                    @endif
                </div>

                <div class="nmsdw-temp-meter">
                    @if($row['charge_percent'] !== null)
                        @include('widgets.partials.nmsdw-meter', [
                            'percent' => $row['charge_percent'],
                            'status' => $row['status'],
                            'caption' => __('Charge :charge', ['charge' => $row['charge_label']])
                                . ($row['voltage'] !== null ? ' · ' . number_format($row['voltage'], 1) . ' V' : ''),
                        ])
                    @else
                        <div class="nmsdw-temp-caption">
                            @if($row['voltage'] !== null){{ number_format($row['voltage'], 1) }} V @endif
                            @if($row['load_watts'] !== null)&middot; {{ number_format($row['load_watts'], 0) }} W @endif
                        </div>
                    @endif
                </div>

                @include('widgets.partials.nmsdw-pill', [
                    'status' => $row['status'],
                    'label' => match($row['status']) {
                        'critical' => __('CRIT'),
                        'warning' => __('WARN'),
                        'unknown' => __('N/A'),
                        default => __('OK'),
                    },
                ])
            </div>
        @endforeach

        @if($show === 'problems')
            <div class="nmsdw-note">
                {{ __('Showing sites with a power or battery condition. :count monitored in total.', ['count' => $site_count]) }}
            </div>
        @endif
    @endif
</div>
