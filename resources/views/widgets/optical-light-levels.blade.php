@include('widgets.partials.nmsdw-style')

<div class="{{ $widget_classes }} nmsdw-optical">
    @if($show_header)

        <div class="nmsdw-head">{{ $heading ?: __('Optical light levels') }}</div>
        <div class="nmsdw-sub">
            {{ $group_label }} &middot; {{ __('ranked by margin above the low threshold') }}
        </div>
    @endif

    @include('widgets.partials.nmsdw-regex-warning', ['problems' => $regex_problems])

    @if(empty($rows))
        @include('widgets.partials.nmsdw-empty', [
            'message' => __('No optical readings matched.'),
            'hint' => __('This widget needs transceivers that report digital diagnostics (sensor class "dbm"). If nothing appears, the optics in use may not support DDM.'),
        ])
    @else
        @if(in_array($layout, ['cards', 'compact', 'tiles'], true))
        {{-- Alternative layouts share one renderer; each widget only supplies records. --}}
        @php
            $records = collect($rows)->map(fn ($r) => [
                'title' => e($r['sensor']->device?->displayName() ?? __('Unknown device')),
                'subtitle' => $r['sensor']->sensor_descr,
                'value' => number_format($r['current'], 2) . ' dBm',
                'unit' => $r['margin'] === null
                    ? __('no threshold')
                    : __(':v dB margin', ['v' => number_format($r['margin'], 2)]),
                'status' => $r['status'],
                'meta' => array_values(array_filter([
                    $r['low'] !== null ? [__('Low'), number_format($r['low'], 2)] : null,
                    $r['high'] !== null ? [__('High'), number_format($r['high'], 2)] : null,
                    $r['direction'] ? [__('Dir'), strtoupper($r['direction'])] : null,
                ])),
                'href' => $r['port'] ? \LibreNMS\Util\Url::portUrl($r['port']) : null,
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
                    <th class="nmsdw-nowrap">{{ __('Level') }}</th>
                    @if($cols['margin'])
                        <th class="nmsdw-nowrap">{{ __('Margin') }}</th>
                    @endif
                    @if($cols['thresholds'])
                        <th class="nmsdw-hide-narrow nmsdw-nowrap">{{ __('Thresholds') }}</th>
                    @endif
                    @if($cols['optic'])
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
                        @if($cols['margin'])
                            <td class="nmsdw-nowrap">
                                @include('widgets.partials.nmsdw-pill', [
                                    'status' => $row['status'],
                                    'label' => $row['margin'] === null ? __('n/a') : number_format($row['margin'], 2) . ' dB',
                                ])
                            </td>
                        @endif
                        @if($cols['thresholds'])
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
                        @endif
                        @if($cols['optic'])
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
    @endif

        @if($skipped_no_limit > 0)
            <div class="nmsdw-note">
                {{ __(':count readings hidden because the optic reports no low threshold.', ['count' => $skipped_no_limit]) }}
            </div>
        @endif
    @endif
</div>
