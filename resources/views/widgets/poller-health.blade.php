@include('widgets.partials.nmsdw-style')

<div class="{{ $widget_classes }} nmsdw-poller">
    @if($show_header)

        <div class="nmsdw-head">{{ __('Poller health') }}</div>
        <div class="nmsdw-sub">
            {{ $group_label }} &middot;
            {{ __('stale after :count minutes without a poll', ['count' => $stale_minutes]) }}
        </div>
    @endif

    <div class="nmsdw-tiles">
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['total'], 'label' => __('Devices')])
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['fresh'], 'label' => __('Fresh')])
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['stale'], 'label' => __('Stale')])
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['never_polled'], 'label' => __('Never polled')])
    </div>

    @if($show_pollers && $pollers->isNotEmpty())
        <div class="nmsdw-rows" style="margin-bottom: 10px;">
            @foreach($pollers as $poller)
                <div class="nmsdw-row {{ $poller['active'] ? 'nmsdw-row-ok' : 'nmsdw-row-down' }}">
                    <span class="nmsdw-row-name">
                        {{ $poller['name'] }}
                        <span class="nmsdw-sec">
                            {{ $poller['version'] }}
                            @if($poller['last_report'])
                                &middot; {{ __('reported') }} {{ \Carbon\Carbon::parse($poller['last_report'])->diffForHumans(null, true) }} {{ __('ago') }}
                            @else
                                &middot; {{ __('never reported') }}
                            @endif
                        </span>
                    </span>
                    @include('widgets.partials.nmsdw-pill', [
                        'status' => $poller['active'] ? 'ok' : 'critical',
                        'label' => $poller['active'] ? __('UP') : __('STALE'),
                    ])
                </div>
            @endforeach
        </div>
    @endif

    @if(empty($rows))
        @include('widgets.partials.nmsdw-empty', [
            'message' => __('All devices are polling on schedule.'),
            'hint' => __('Every accessible device has been polled within the last :count minutes.', ['count' => $stale_minutes]),
        ])
    @else
        @if(in_array($layout, ['cards', 'compact', 'tiles'], true))
        {{-- Alternative layouts share one renderer; each widget only supplies records. --}}
        @php
            $records = collect($rows)->map(fn ($r) => [
                'title' => e($r['device']->displayName()),
                'subtitle' => null,
                'value' => $r['stale_for'] ? $r['stale_for'] : __('never'),
                'unit' => $r['stale_for'] ? __('since last poll') : __('polled'),
                'status' => $r['stale_for'] ? 'warning' : 'critical',
                'meta' => [
                    [__('State'), $r['device']->status ? __('up') : __('down')],
                ],
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
                    <th class="nmsdw-hide-narrow">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td class="nmsdw-strong">
                            @include('widgets.partials.nmsdw-device-cell', ['linkDevice' => $row['device']])
                        </td>
                        <td class="nmsdw-nowrap">
                            @if($row['stale_for'])
                                <span title="{{ $row['last_polled'] }}">{{ $row['stale_for'] }} {{ __('ago') }}</span>
                            @else
                                @include('widgets.partials.nmsdw-pill', ['status' => 'critical', 'label' => __('never')])
                            @endif
                        </td>
                        <td class="nmsdw-hide-narrow nmsdw-muted">
                            {{ $row['device']->status ? __('up') : __('down') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

        @if($summary['stale'] > count($rows))
            <div class="nmsdw-note">
                {{ __('Showing :shown of :total stale devices.', ['shown' => count($rows), 'total' => $summary['stale']]) }}
            </div>
        @endif
    @endif
</div>
