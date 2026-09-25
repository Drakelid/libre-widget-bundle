@include('widgets.partials.nmsdw-style')

<div class="{{ $widget_classes }} nmsdw-poller">
    @if($show_header)

        <div class="nmsdw-head">{{ $heading ?: __('Poller health') }}</div>
        <div class="nmsdw-sub">
            {{ $group_label }} &middot;
            {{ __('stale after :count minutes without a poll', ['count' => $stale_minutes]) }}
        </div>
    @endif

    <div class="nmsdw-tiles">
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['total'], 'label' => __('Devices')])
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['fresh'], 'label' => __('Fresh')])
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['stale'], 'label' => __('Stale')])
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['never_polled'], 'label' => __('of those, never polled')])
    </div>

    @if($show_pollers && $poller_inventory_unavailable)
        <div class="nmsdw-alert nmsdw-alert-warn">{{ __('Poller inventory is unavailable. Node health could not be checked; device polling timestamps are shown below.') }}</div>
    @endif
    @if($show_pollers && $pollers->isNotEmpty())
        <div class="nmsdw-rows" style="margin-bottom: 10px;">
            @foreach($pollers as $poller)
                <div class="nmsdw-row {{ !$poller['enabled'] ? '' : ($poller['active'] ? 'nmsdw-row-ok' : 'nmsdw-row-down') }}">
                    <span class="nmsdw-row-name">
                        {{ $poller['name'] }}
                        <span class="nmsdw-sec">
                            {{ $poller['version'] }} &middot; {{ __('Interval') }} {{ $poller['interval'] }} s &middot; {{ __('Groups') }} {{ implode(', ', $poller['groups']) }}
                            @if($poller['last_report'])
                                &middot; {{ __('reported') }} {{ \Carbon\Carbon::parse($poller['last_report'])->diffForHumans(null, true) }} {{ __('ago') }}
                            @else
                                &middot; {{ __('never reported') }}
                            @endif
                        </span>
                    </span>
                    @include('widgets.partials.nmsdw-pill', [
                        'status' => !$poller['enabled'] ? 'unknown' : ($poller['active'] ? 'ok' : 'critical'),
                        'label' => !$poller['enabled'] ? __('DISABLED') : ($poller['active'] ? __('UP') : __('FAILED / STALE')),
                    ])
                </div>
            @endforeach
        </div>
    @endif

    @if(empty($rows))
        @include('widgets.partials.nmsdw-empty', [
            'message' => $summary['total'] === 0
                ? __('No accessible devices match the selected groups and device filters.')
                : __('All matched devices have recent polling timestamps.'),
            'hint' => $summary['total'] === 0
                ? __('Check the selected device groups and disabled-device filter.')
                : __('Every matched device has been polled within the last :count minutes.', ['count' => $stale_minutes]),
        ])
    @else
        @foreach(collect($rows)->groupBy('group_id') as $pollerGroup => $groupRows)
        <div class="nmsdw-sub">{{ $groupRows->first()['group_label'] }} &middot; {{ __(':count stale devices', ['count' => $stale_groups[$pollerGroup] ?? count($groupRows)]) }}</div>
        @if(in_array($layout, ['cards', 'compact', 'tiles'], true))
        {{-- Alternative layouts share one renderer; each widget only supplies records. --}}
        @php
            $records = $groupRows->map(fn ($r) => [
                'title' => e($r['device']->displayName()),
                'subtitle' => $r['disabled'] ? __('Monitoring disabled') : null,
                'observed_at' => $r['observed_at'],
                'value' => $r['stale_for'] ? $r['stale_for'] : __('never'),
                'unit' => $r['stale_for'] ? __('since last poll') : __('polled'),
                'status' => $r['disabled'] ? 'unknown' : ($r['stale_for'] ? 'warning' : 'critical'),
                'meta' => array_values(array_filter([
                    $cols['status'] ? [__('State'), $r['device']->status ? __('up') : __('down')] : null,
                    [__('Configured interval'), $r['interval'] . ' s'],
                    [__('Last poll duration'), $r['duration'] === null ? __('Unavailable') : number_format($r['duration'], 1) . ' s' . ($r['overrun'] ? ' | ' . __('exceeds interval') : '')],
                ])),
                'href' => \LibreNMS\Util\Url::deviceUrl($r['device']),
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
                    <th class="nmsdw-nowrap">{{ __('Last polled') }}</th>
                    @if($cols['status'])
                        <th class="nmsdw-hide-narrow">{{ __('Status') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($groupRows as $row)
                    <tr>
                        <td class="nmsdw-strong">
                            @include('widgets.partials.nmsdw-device-cell', ['linkDevice' => $row['device']])
                            @if($row['disabled'])<span class="nmsdw-sec">{{ __('Monitoring disabled') }}</span>@endif
                            <span class="nmsdw-sec">{{ __('Configured interval') }} {{ $row['interval'] }} s &middot; {{ __('Last duration') }} {{ $row['duration'] === null ? __('Unavailable') : number_format($row['duration'], 1) . ' s' }}
                                @if($row['overrun']) &middot; {{ __('exceeds interval') }} @endif
                            </span>
                        </td>
                        <td class="nmsdw-nowrap">
                            @if($row['stale_for'])
                                @include('widgets.partials.nmsdw-data-age', ['timestamp' => $row['observed_at'], 'stale_after' => $stale_minutes * 60])
                            @else
                                @include('widgets.partials.nmsdw-pill', ['status' => 'critical', 'label' => __('never')])
                            @endif
                        </td>
                        @if($cols['status'])
                            <td class="nmsdw-hide-narrow nmsdw-muted">
                                {{ $row['device']->status ? __('up') : __('down') }}
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

        @endforeach
        @if($summary['stale'] > count($rows))
            <div class="nmsdw-note">
                {{ __('Showing :shown of :total stale devices.', ['shown' => count($rows), 'total' => $summary['stale']]) }}
            </div>
        @endif
    @endif
    @include('widgets.partials.nmsdw-result-count', ['shown' => count($rows), 'matched' => $matched_count, 'noun' => __('stale devices')])
</div>
