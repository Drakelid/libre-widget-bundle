@include('widgets.partials.nmsdw-style')

<div class="nmsdw-widget nmsdw-optical">
    <div class="nmsdw-head">{{ __('Optical light levels') }}</div>
    <div class="nmsdw-sub">
        {{ $group_label }} &middot; {{ __('ranked by margin above the low threshold') }}
    </div>

    @include('widgets.partials.nmsdw-regex-warning', ['problems' => $regex_problems])

    @if(empty($rows))
        @include('widgets.partials.nmsdw-empty', [
            'message' => __('No optical readings matched.'),
            'hint' => __('This widget needs transceivers that report digital diagnostics (sensor class "dbm"). If nothing appears, the optics in use may not support DDM.'),
        ])
    @else
        <table class="nmsdw-table">
            <thead>
                <tr>
                    <th>{{ __('Device') }}</th>
                    <th>{{ __('Interface') }}</th>
                    <th class="nmsdw-nowrap">{{ __('Level') }}</th>
                    <th class="nmsdw-nowrap">{{ __('Margin') }}</th>
                    <th class="nmsdw-hide-narrow nmsdw-nowrap">{{ __('Thresholds') }}</th>
                    @if($show_transceiver_details)
                        <th class="nmsdw-hide-narrow">{{ __('Optic') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    @php($sensor = $row['sensor'])
                    <tr>
                        <td class="nmsdw-strong">
                            @include('widgets.partials.nmsdw-device-cell', ['linkDevice' => $sensor->device])
                        </td>
                        <td>
                            @if($row['port'])
                                <x-port-link :port="$row['port']" />
                            @endif
                            <span class="nmsdw-sec">{{ $sensor->sensor_descr }}</span>
                        </td>
                        <td class="nmsdw-nowrap">
                            <span class="nmsdw-strong">{{ number_format($row['current'], 2) }} dBm</span>
                            @if($row['direction'])
                                <span class="nmsdw-sec">{{ strtoupper($row['direction']) }}</span>
                            @endif
                        </td>
                        <td class="nmsdw-nowrap">
                            @include('widgets.partials.nmsdw-pill', [
                                'status' => $row['status'],
                                'label' => $row['margin'] === null ? __('n/a') : number_format($row['margin'], 2) . ' dB',
                            ])
                        </td>
                        <td class="nmsdw-hide-narrow nmsdw-muted nmsdw-nowrap">
                            @if($row['low'] !== null)
                                <div>{{ __('Low') }}: {{ number_format($row['low'], 2) }}</div>
                            @endif
                            @if($row['high'] !== null)
                                <div>{{ __('High') }}: {{ number_format($row['high'], 2) }}</div>
                            @endif
                            @if($row['low'] === null && $row['high'] === null)
                                <div>{{ __('none reported') }}</div>
                            @endif
                        </td>
                        @if($show_transceiver_details)
                            <td class="nmsdw-hide-narrow nmsdw-muted">
                                @if($row['transceiver'])
                                    <div>{{ $row['transceiver']->vendor }} {{ $row['transceiver']->model }}</div>
                                    <div class="nmsdw-sec">
                                        @if($row['transceiver']->wavelength){{ $row['transceiver']->wavelength }}nm @endif
                                        @if($row['transceiver']->distance){{ $row['transceiver']->distance }}m @endif
                                    </div>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($skipped_no_limit > 0)
            <div class="nmsdw-note">
                {{ __(':count readings hidden because the optic reports no low threshold.', ['count' => $skipped_no_limit]) }}
            </div>
        @endif
    @endif
</div>
