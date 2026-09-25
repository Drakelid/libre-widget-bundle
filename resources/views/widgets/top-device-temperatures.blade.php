@include('widgets.partials.nmsdw-style')

<div class="{{ $widget_classes }} nmsdw-temps">
    @if($show_header)
        <div class="nmsdw-head">{{ $heading ?: __('Device temperatures') }}</div>
        <div class="nmsdw-sub">
            {{ $group_label }} &middot;
            {{ $include_module_sensors ? __('all temperature sensors') : __('device / chassis only') }} &middot; {{ $ranking === 'margin' ? __('least thermal headroom first') : __('highest temperature first') }}
        </div>
    @endif
    @include('widgets.partials.nmsdw-regex-warning', ['problems' => $regex_problems])

    @if($include_matches_everything)
        <div class="nmsdw-alert nmsdw-alert-warn">
            {{ __('The include regex matches every sensor. A trailing "|" makes the pattern match anything.') }}
        </div>
    @endif

    @if($rows->isEmpty())
        @include('widgets.partials.nmsdw-empty', [
            'message' => $candidate_count === 0
                ? __('No temperature readings are available in the selected device and source scope.')
                : __('Temperature readings were found, but none passed the sensor filters.'),
            'hint' => __('Source: :source. Groups: :groups.', [
                'source' => $include_module_sensors ? __('all temperature sensors') : __('device / chassis sensors only'),
                'groups' => $group_label,
            ]),
        ])
    @else
@if(in_array($layout, ['cards', 'compact', 'tiles'], true))
        {{-- Alternative layouts share one renderer; each widget only supplies records. --}}
        @php
            $records = collect($rows)->map(fn ($r) => [
                'title' => e($r['sensor']->device?->displayName() ?? __('Unknown device')),
                'subtitle' => $r['sensor']->sensor_descr,
                'observed_at' => $r['observed_at'],
                'age_label' => 'Device polled',
                'value' => $r['current_text'],
                'unit' => null,
                'status' => $r['status'],
                'bar' => $r['percent'],
                'meta' => [
                    [__('Limit'), \Drakelid\NmsDashWidgets\Support\Format::temperature($r['limits']['limit']) . ' (' . __($r['limits']['limit_source']) . ')'],
                    [__('Headroom'), number_format($r['margin'], 1) . ' Â°C'],
                    [__('24 h change'), $r['trend']['available'] ? sprintf('%+.1f Â°C', $r['trend']['delta']) : ($r['trend']['reason'] ?? __('Unavailable'))],
                    [__('Warn'), \Drakelid\NmsDashWidgets\Support\Format::temperature($r['limits']['warn'])],
                ],
                'href' => $r['sensor']->device
                    ? \LibreNMS\Util\Url::deviceUrl($r['sensor']->device, ['tab' => 'health', 'metric' => 'temperature'])
                    : null,
            ])->all();
        @endphp

        @include('widgets.partials.nmsdw-records', [
            'records' => $records,
            'layout' => $layout,
            'card_min_width' => $card_min_width,
        ])
    @else
                @foreach($rows as $row)
            @php($sensor = $row['sensor'])
            <div class="nmsdw-temp-row nmsdw-temp-{{ $row['status'] }}">
                <div class="nmsdw-temp-name">
                    @include('widgets.partials.nmsdw-device-cell', ['linkDevice' => $sensor->device])
                    <span class="nmsdw-sec">{{ $sensor->sensor_descr }}</span>
                    @include('widgets.partials.nmsdw-data-age', ['age_label' => 'Device polled', 'timestamp' => $row['observed_at']])
                </div>

                <div class="nmsdw-temp-value">{{ $row['current_text'] }}<span class="nmsdw-sec">{{ __('Headroom') }} {{ number_format($row['margin'], 1) }} Â°C</span></div>

                <div class="nmsdw-temp-meter">
                    @include('widgets.partials.nmsdw-meter', [
                        'percent' => $row['percent'],
                        'status' => $row['status'],
                        'caption' => $row['caption'] . ' (' . __($row['limits']['limit_source']) . ')',
                    ])
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

    @endif
        @foreach($rows as $row)
            <details class="nmsdw-note">
                <summary>{{ $row['sensor']->device?->displayName() }} &middot; {{ __('Sensor details') }} ({{ count($row['other_sensors']) }})</summary>
                <p>{{ __('24 h change') }}: {{ $row['trend']['available'] ? sprintf('%+.1f Â°C', $row['trend']['delta']) : ($row['trend']['reason'] ?? __('Unavailable')) }}</p>
                @foreach($row['other_sensors'] as $other)
                    <div>
                        <a href="{{ \LibreNMS\Util\Url::graphPageUrl('sensor_temperature', ['id' => $other['id']]) }}">{{ $other['descr'] }}</a>
                        {{ number_format($other['current'], 1) }} Â°C &middot; {{ __('Headroom') }} {{ number_format($other['margin'], 1) }} Â°C
                        @include('widgets.partials.nmsdw-pill', ['status' => $other['status'], 'label' => __(strtoupper($other['status']))])
                        @include('widgets.partials.nmsdw-data-age', ['age_label' => 'Device polled', 'timestamp' => $other['observed_at']])
                    </div>
                @endforeach
            </details>
        @endforeach
        @if($excluded_module_count > 0 || $excluded_regex_count > 0)
            <div class="nmsdw-note">
                @if($excluded_module_count > 0)
                    {{ __(':count module or transceiver sensors hidden.', ['count' => $excluded_module_count]) }}
                @endif
                @if($excluded_regex_count > 0)
                    {{ __(':count sensors excluded by the regex filters.', ['count' => $excluded_regex_count]) }}
                @endif
            </div>
        @endif
    @endif
    @include('widgets.partials.nmsdw-result-count', ['shown' => count($rows), 'matched' => $matched_count, 'noun' => __('devices')])
</div>
