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

    @if(empty($rows))
        @include('widgets.partials.nmsdw-empty', [
            'message' => $battery_sites === 0
                ? __('No battery or runtime sensors found.')
                : __('All sites are on mains with healthy reserve.'),
            'hint' => $battery_sites === 0
                ? __('This widget looks for charge and runtime sensors. UPS or rectifier support may not be discovered on these devices, or "Only devices with battery data" can be turned off to include anything reporting voltage or power.')
                : null,
        ])
    @else
@if(in_array($layout, ['cards', 'compact', 'tiles'], true))
        {{-- Alternative layouts share one renderer; each widget only supplies records. --}}
        @php
            $records = collect($rows)->map(fn ($r) => [
                'title' => e($r['label']),
                'subtitle' => !empty($r['states']) ? $r['states'][0]['text'] : null,
                'value' => $r['runtime_label'] ?: ($r['charge_label'] ?: '—'),
                'unit' => $r['runtime_label'] ? __('runtime left') : ($r['charge_label'] ? __('charge') : null),
                'status' => $r['status'],
                'bar' => $r['charge_percent'] ?? 0,
                'meta' => array_values(array_filter([
                    $r['voltage'] !== null ? [__('Voltage'), number_format($r['voltage'], 1) . ' V'] : null,
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

    @endif
        @if($show === 'problems' || $suspect_sites > 0)
            <div class="nmsdw-note">
                @if($show === 'problems')
                    {{ __('Showing sites with a power or battery condition. :count with battery data in total.', ['count' => $battery_sites]) }}
                @endif
                @if($suspect_sites > 0)
                    {{ __(':count sites reported a reading outside plausible range (for example a negative battery runtime); those readings were ignored.', ['count' => $suspect_sites]) }}
                @endif
            </div>
        @endif
    @endif
</div>
