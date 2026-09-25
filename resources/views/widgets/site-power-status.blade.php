@include('widgets.partials.nmsdw-style')

<div class="{{ $widget_classes }} nmsdw-power">
    @if($show_header)

        <div class="nmsdw-head">{{ $heading ?: __('Site power and battery') }}</div>
        <div class="nmsdw-sub">
            {{ $group_label }} &middot;
            {{ $group_by === 'location' ? __('grouped by location') : __('grouped by device') }} &middot;
            {{ __(':count with battery data', ['count' => $battery_sites]) }}
        </div>
    @endif

    @if($suspect_sites > 0)
        <div class="nmsdw-note">
            {{ __(':count sites reported a reading outside plausible range (for example a negative battery runtime); those readings were ignored.', ['count' => $suspect_sites]) }}
        </div>
    @endif

    @if(empty($rows))
        @include('widgets.partials.nmsdw-empty', [
            'message' => $site_count === 0
                ? __('No current power or battery readings are available in the selected device scope.')
                : ($battery_only && $battery_sites === 0
                    ? __('Power readings were found, but no sites passed the battery-data filter.')
                    : __('No power or battery conditions detected in the available readings.')),
            'hint' => $site_count === 0
                ? __('Check device groups, sensor discovery and polling.')
                : ($battery_only && $battery_sites === 0
                    ? __('Turn off "Only devices with battery data" to include sites reporting voltage or power without charge or runtime sensors.')
                    : null),
        ])
    @else
@if(in_array($layout, ['cards', 'compact', 'tiles'], true))
        {{-- Alternative layouts share one renderer; each widget only supplies records. --}}
        @php
            $records = collect($rows)->map(fn ($r) => [
                'title' => e($r['label']),
                'subtitle' => $r['conditions'][0]['label'] ?? null,
                'observed_at' => $r['observed_at'],
                'age_label' => 'Device polled',
                'value' => $r['runtime_label'] ?: ($r['charge_label'] ?: 'â€”'),
                'unit' => $r['runtime_label'] ? __('runtime left') : ($r['charge_label'] ? __('charge') : null),
                'status' => $r['status'],
                'bar' => $r['charge_percent'] ?? 0,
                'meta' => array_values(array_filter([
                    $r['voltage'] !== null ? [__('Voltage min / max'), number_format($r['voltage_min'], 1) . ' / ' . number_format($r['voltage_max'], 1) . ' V'] : null,
                    $r['load_watts'] !== null ? [__('Load'), number_format($r['load_watts'], 0) . ' W'] : null,
                    $group_by === 'location' ? [__('Devices'), $r['device_count']] : null,
                ])),
                'href' => $r['device'] ? \LibreNMS\Util\Url::deviceUrl($r['device']) : null,
            ])->all();
        @endphp

        @include('widgets.partials.nmsdw-records', [
            'records' => $records,
            'layout' => $layout,
            'card_min_width' => $card_min_width,
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

                @include('widgets.partials.nmsdw-data-age', ['age_label' => 'Device polled', 'timestamp' => $row['observed_at']])
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
                                . ($row['voltage'] !== null ? ' Â· ' . number_format($row['voltage_min'], 1) . ' / ' . number_format($row['voltage_max'], 1) . ' V' : ''),
                        ])
                    @else
                        <div class="nmsdw-temp-caption">
                            @if($row['voltage'] !== null){{ number_format($row['voltage_min'], 1) }} / {{ number_format($row['voltage_max'], 1) }} V @endif
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

    @endif
        @foreach($rows as $row)
            <details class="nmsdw-note">
                <summary>{{ $row['label'] }} &middot; {{ __('Conditions and site members') }} ({{ $row['device_count'] }})</summary>
                @forelse($row['conditions'] as $condition)
                    <div>
                        <strong>{{ $condition['label'] }}</strong>
                        @if($condition['source'])
                            @if($condition['source']['device'])
                                @include('widgets.partials.nmsdw-device-cell', ['linkDevice' => $condition['source']['device']])
                            @endif
                            {{ $condition['source']['descr'] }}
                            @include('widgets.partials.nmsdw-data-age', ['age_label' => 'Device polled', 'timestamp' => $condition['source']['observed_at']])
                        @endif
                    </div>
                @empty
                    <div>{{ __('No threshold conditions detected.') }}</div>
                @endforelse
                <div class="nmsdw-sec">{{ __('Low reserve is inferred from thresholds; power-source state is shown only as reported by a device.') }}</div>
                @forelse($row['reported_states'] as $state)
                    <div>{{ __('Reported state') }}: {{ $state['device']?->displayName() }} &middot; {{ $state['descr'] }} &middot; {{ $state['text'] }}</div>
                @empty
                    <div>{{ __('No explicit power-source state is available.') }}</div>
                @endforelse
                @foreach($row['devices'] as $member)
                    <div>@include('widgets.partials.nmsdw-device-cell', ['linkDevice' => $member])
                        @include('widgets.partials.nmsdw-data-age', ['age_label' => 'Device polled', 'timestamp' => $member->last_polled])
                    </div>
                @endforeach
            </details>
        @endforeach
        @if($show === 'problems')
            <div class="nmsdw-note">
                {{ __('Showing sites with a power or battery condition. :count with battery data in total.', ['count' => $battery_sites]) }}
            </div>
        @endif
    @endif
    @include('widgets.partials.nmsdw-result-count', ['shown' => count($rows), 'matched' => $matched_count, 'noun' => __('sites')])
</div>
