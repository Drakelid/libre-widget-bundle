@include('widgets.partials.nmsdw-style')

<div class="nmsdw-widget nmsdw-temps">
    @include('widgets.partials.nmsdw-regex-warning', ['problems' => $regex_problems])

    @if($include_matches_everything)
        <div class="nmsdw-alert nmsdw-alert-warn">
            {{ __('The include regex matches every sensor. A trailing "|" makes the pattern match anything.') }}
        </div>
    @endif

    @if($rows->isEmpty())
        @include('widgets.partials.nmsdw-empty', [
            'message' => __('No temperature sensors matched.'),
            'hint' => __('Source: :source. Groups: :groups.', [
                'source' => $include_module_sensors ? __('all temperature sensors') : __('device / chassis sensors only'),
                'groups' => $group_label,
            ]),
        ])
    @else
        @foreach($rows as $row)
            @php($sensor = $row['sensor'])
            <div class="nmsdw-temp-row nmsdw-temp-{{ $row['status'] }}">
                <div class="nmsdw-temp-name">
                    @include('widgets.partials.nmsdw-device-cell', ['linkDevice' => $sensor->device])
                    <span class="nmsdw-sec">{{ $sensor->sensor_descr }}</span>
                </div>

                <div class="nmsdw-temp-value">{{ $row['current_text'] }}</div>

                <div class="nmsdw-temp-meter">
                    @include('widgets.partials.nmsdw-meter', [
                        'percent' => $row['percent'],
                        'status' => $row['status'],
                        'caption' => $row['caption'],
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
</div>
