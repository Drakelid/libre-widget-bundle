@include('widgets.partials.nmsdw-style')
<div class="{{ $widget_classes }} nmsdw-optical">
    @if($show_header)
        <div class="nmsdw-head">{{ $heading ?: __('Optical light levels') }}</div>
        <div class="nmsdw-sub">{{ $group_label }} &middot; {{ $ordering }} &middot; {{ __('Active interfaces on online devices') }}</div>
    @endif
    @include('widgets.partials.nmsdw-regex-warning', ['problems' => $regex_problems])
    <div class="nmsdw-note">
        {{ __('Excluded: :mapping without an interface mapping, :inactive on inactive interfaces, :limits without a low threshold, :direction by direction, :regex by regex.', ['mapping' => $skipped_unmapped, 'inactive' => $skipped_inactive, 'limits' => $skipped_no_limit, 'direction' => $skipped_direction, 'regex' => $skipped_regex]) }}
    </div>
    @if(empty($rows))
        @include('widgets.partials.nmsdw-empty', [
            'message' => $total_seen + $skipped_unmapped + $skipped_inactive === 0
                ? __('No optical readings are available on online devices in the selected scope.')
                : __('Optical readings were found, but none passed the active-interface and sensor filters.'),
            'hint' => __('Check interface mappings, device groups and threshold filters. Custom low thresholds can include optics without vendor limits.'),
        ])
    @elseif(in_array($layout, ['cards', 'compact', 'tiles'], true))
        @php
            $records = collect($rows)->map(function ($r) use ($cols) {
                $levels = []; $margins = []; $limits = []; $changes = [];
                foreach ($r['readings'] as $reading) {
                    $dir = strtoupper($reading['direction'] ?? '?');
                    $levels[] = $dir . ' ' . number_format($reading['current'], 2) . ' dBm';
                    $margins[] = $dir . ' ' . ($reading['margin'] === null ? __('n/a') : number_format($reading['margin'], 2) . ' dB');
                    $limits[] = $dir . ' ' . __('Low') . ' ' . ($reading['low'] === null ? __('n/a') : number_format($reading['low'], 2)) . ' / ' . __('High') . ' ' . ($reading['high'] === null ? __('n/a') : number_format($reading['high'], 2));
                    $changes[] = $dir . ' ' . ($reading['trend']['available'] ? sprintf('%+.2f dB', $reading['trend']['delta']) : ($reading['trend']['reason'] ?? __('Unavailable')));
                }
                return [
                    'title' => e($r['sensor']->device?->displayName() ?? __('Unknown device')),
                    'subtitle' => ($r['port']?->ifName ?: $r['sensor']->sensor_descr) . ($r['lane'] === null ? '' : ' | ' . __('Lane') . ' ' . $r['lane']),
                    'value' => implode(' / ', $levels),
                    'status' => $r['status'],
                    'observed_at' => $r['observed_at'],
                'age_label' => 'Device polled',
                    'meta' => array_values(array_filter([
                        $cols['margin'] ? [__('Margin'), implode(' / ', $margins)] : null,
                        $cols['thresholds'] ? [__('Thresholds'), implode(' | ', $limits)] : null,
                        $cols['optic'] && $r['transceiver'] ? [__('Optic'), trim($r['transceiver']->vendor . ' ' . $r['transceiver']->model)] : null,
                        [__('24 h change'), implode(' / ', $changes)],
                    ])),
                    'href' => $r['port'] ? \LibreNMS\Util\Url::portUrl($r['port']) : null,
                ];
            })->all();
        @endphp
        @include('widgets.partials.nmsdw-records', ['records' => $records, 'layout' => $layout, 'card_min_width' => $card_min_width])
    @else
        <table class="nmsdw-table">
            <thead><tr><th>{{ __('Device / interface') }}</th><th>{{ __('RX / TX') }}</th>
                @if($cols['margin'])<th>{{ __('Margin') }}</th>@endif
                @if($cols['thresholds'])<th>{{ __('Thresholds') }}</th>@endif
                @if($cols['optic'])<th>{{ __('Optic') }}</th>@endif
            </tr></thead>
            <tbody>
            @foreach($rows as $row)
                <tr>
                    <td>
                        @include('widgets.partials.nmsdw-device-cell', ['linkDevice' => $row['sensor']->device])
                        @if($row['port'])<div><x-port-link :port="$row['port']" /></div>@endif
                        @if($row['lane'] !== null)<span class="nmsdw-sec">{{ __('Lane') }} {{ $row['lane'] }}</span>@endif
                        @include('widgets.partials.nmsdw-data-age', ['age_label' => 'Device polled', 'timestamp' => $row['observed_at']])
                    </td>
                    <td>@foreach($row['readings'] as $reading)
                        <div><a href="{{ \LibreNMS\Util\Url::graphPageUrl('sensor_dbm', ['id' => $reading['sensor']->sensor_id]) }}" title="{{ $reading['sensor']->sensor_descr }}">{{ strtoupper($reading['direction'] ?? '?') }} {{ number_format($reading['current'], 2) }} dBm</a></div>
                    @endforeach</td>
                    @if($cols['margin'])<td>@foreach($row['readings'] as $reading)
                        <div>@include('widgets.partials.nmsdw-pill', ['status' => $reading['status'], 'label' => strtoupper($reading['direction'] ?? '?') . ' ' . ($reading['margin'] === null ? __('n/a') : number_format($reading['margin'], 2) . ' dB')])</div>
                    @endforeach</td>@endif
                    @if($cols['thresholds'])<td>@foreach($row['readings'] as $reading)
                        <div>{{ strtoupper($reading['direction'] ?? '?') }} {{ __('Low') }} {{ $reading['low'] === null ? __('n/a') : number_format($reading['low'], 2) }} / {{ __('High') }} {{ $reading['high'] === null ? __('n/a') : number_format($reading['high'], 2) }}
                            @if($reading['custom']['low'] || $reading['custom']['high']) ({{ __('custom') }}) @endif
                        </div>
                    @endforeach</td>@endif
                    @if($cols['optic'])<td>@if($row['transceiver']){{ $row['transceiver']->vendor }} {{ $row['transceiver']->model }}<span class="nmsdw-sec">{{ $row['transceiver']->wavelength }} nm &middot; {{ $row['transceiver']->distance }} m</span>@endif</td>@endif
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
    @foreach($rows as $row)
        <details class="nmsdw-note">
            <summary>{{ $row['sensor']->device?->displayName() }} &middot; {{ $row['port']?->ifName ?: $row['sensor']->sensor_descr }} @if($row['lane'] !== null) &middot; {{ __('Lane') }} {{ $row['lane'] }} @endif &middot; {{ __('Optical history and graphs') }}</summary>
            @foreach($row['readings'] as $reading)
                <div>
                    <a href="{{ \LibreNMS\Util\Url::graphPageUrl('sensor_dbm', ['id' => $reading['sensor']->sensor_id]) }}">{{ $reading['sensor']->sensor_descr }}</a>
                    &middot; {{ __('24 h change') }}: {{ $reading['trend']['available'] ? sprintf('%+.2f dB', $reading['trend']['delta']) : ($reading['trend']['reason'] ?? __('Unavailable')) }}
                    @if($reading['trend']['available'] && $reading['trend']['delta'] < 0) &middot; {{ __('Falling optical power') }} @endif
                    @include('widgets.partials.nmsdw-data-age', ['age_label' => 'Device polled', 'timestamp' => $reading['observed_at']])
                </div>
            @endforeach
        </details>
    @endforeach
    @include('widgets.partials.nmsdw-result-count', ['shown' => $displayed_readings, 'matched' => $matched_count, 'noun' => __('readings')])
    <div class="nmsdw-note">{{ __(':count interface/lane rows shown. Ambiguous RX/TX lanes remain separate.', ['count' => count($rows)]) }}</div>
</div>
