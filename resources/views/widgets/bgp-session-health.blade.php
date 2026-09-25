@include('widgets.partials.nmsdw-style')

<div class="{{ $widget_classes }} nmsdw-bgp">
    @if($show_header)

        <div class="nmsdw-head">{{ $heading ?: __('BGP session health') }}</div>
        <div class="nmsdw-sub">{{ $group_label }}</div>
    @endif

    <div class="nmsdw-tiles">
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['total'], 'label' => __('Sessions')])
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['established'], 'label' => __('Established')])
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['down'], 'label' => __('Down')])
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['recent'], 'label' => __('Recently up')])
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['admin_down'], 'label' => __('Administratively shut')])
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['unknown'], 'label' => __('Unknown admin state')])
    </div>

    @if(empty($rows))
        @include('widgets.partials.nmsdw-empty', [
            'message' => $summary['total'] === 0
                ? __('No BGP peers found.')
                : ($show === 'established_only' ? __('No established sessions match these filters.') : __('No sessions match this display mode. Shut and unknown sessions may be excluded.')),
            'hint' => $summary['total'] === 0
                ? __('The bgp-peers discovery module may be disabled, or these devices do not run BGP.')
                : null,
        ])
    @else
        @if(in_array($layout, ['cards', 'compact', 'tiles'], true))
        {{-- Alternative layouts share one renderer; each widget only supplies records. --}}
        @php
            $records = collect($rows)->map(fn ($r) => [
                'title' => e($r['peer']->device?->displayName() ?? __('Unknown device')),
                'subtitle' => $r['peer']->bgpPeerIdentifier . ' · AS' . $r['peer']->bgpPeerRemoteAs,
                'value' => $r['state_label'],
                'unit' => $r['peer']->astext ?: null,
                'status' => $r['status'],
                'observed_at' => $r['observed_at'],
                'meta' => array_values(array_filter([
                    $cols['prefixes'] ? [__('Prefixes'), $r['prefix'] ? number_format($r['prefix']['accepted']) : __('Unavailable for this peer/context')] : null,
                    $cols['uptime'] ? [__('Uptime'), $r['uptime_seconds'] . ' s'] : null,
                    $cols['error'] ? [__('Last error'), $r['peer']->bgpPeerLastErrorText] : null,
                    [__('VRF ID'), $r['peer']->vrf_id ?? '0'],
                    !empty($r['reasons']) ? [__('Reason'), implode('; ', $r['reasons'])] : null,
                    $r['admin_shut'] ? [__('Admin'), __('Shut')] : null,
                    [__('Sample age source'), __('Device last poll')],
                ])),
                'href' => $r['peer']->device
                    ? \LibreNMS\Util\Url::deviceUrl($r['peer']->device, ['tab' => 'routing', 'proto' => 'bgp'])
                    : null,
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
                    <th>{{ __('Peer') }}</th>
                    <th class="nmsdw-nowrap">{{ __('State') }}</th>
                    @if($cols['uptime'])
                        <th class="nmsdw-hide-narrow nmsdw-nowrap">{{ __('Uptime') }}</th>
                    @endif
                    @if($cols['prefixes'])
                        <th class="nmsdw-hide-narrow nmsdw-nowrap">{{ __('Prefixes') }}</th>
                    @endif
                    @if($cols['error'])
                        <th class="nmsdw-hide-narrow">{{ __('Last error') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    @php($peer = $row['peer'])
                    <tr>
                        <td class="nmsdw-strong">
                            @include('widgets.partials.nmsdw-device-cell', ['linkDevice' => $peer->device])
                            <span class="nmsdw-sec">{{ __('Device last poll') }}</span>
                            @include('widgets.partials.nmsdw-data-age', ['timestamp' => $row['observed_at']])
                        </td>
                        <td>
                            @if($peer->device)
                                <a class="nmsdw-strong" href="{{ \LibreNMS\Util\Url::deviceUrl($peer->device, ['tab' => 'routing', 'proto' => 'bgp']) }}">{{ $peer->bgpPeerIdentifier }}</a>
                            @else
                                <span class="nmsdw-strong">{{ $peer->bgpPeerIdentifier }}</span>
                            @endif
                            <span class="nmsdw-sec">
                                AS{{ $peer->bgpPeerRemoteAs }}@if($peer->astext) &middot; {{ $peer->astext }}@endif
                            </span>
                            @if($peer->bgpPeerDescr)
                                <span class="nmsdw-sec">{{ $peer->bgpPeerDescr }}</span>
                            @endif
                            <span class="nmsdw-sec">{{ __('VRF ID') }}: {{ $peer->vrf_id ?? '0' }}</span>
                        </td>
                        <td class="nmsdw-nowrap">
            {{-- Show the protocol state as reported. "shut" is only claimed when the
                 device actually said the session is administratively down. --}}
                            @include('widgets.partials.nmsdw-pill', [
                                'status' => $row['status'],
                                'label' => $row['state_label'],
                            ])
                            @if($row['admin_shut'])
                                <span class="nmsdw-sec">{{ __('admin shut') }}</span>
                            @endif
                            @foreach($row['reasons'] as $reason)<span class="nmsdw-sec">{{ $reason }}</span>@endforeach
                        </td>
                        @if($cols['uptime'])
                            <td class="nmsdw-hide-narrow nmsdw-muted nmsdw-nowrap">
                                @if($row['uptime_seconds'] > 0)
                                    {{ \Carbon\CarbonInterval::seconds($row['uptime_seconds'])->cascade()->forHumans(['short' => true, 'parts' => 2]) }}
                                @else
                                    &mdash;
                                @endif
                            </td>
                        @endif
                        @if($cols['prefixes'])
                            <td class="nmsdw-hide-narrow nmsdw-nowrap">
                                @if($row['prefix'])
                                    <div class="{{ $row['prefix']['dropped'] ? 'nmsdw-strong' : '' }}">
                                        {{ number_format($row['prefix']['accepted']) }}
                                        @if($row['prefix']['dropped'])
                                            <span class="nmsdw-sec">{{ __('was') }} {{ number_format($row['prefix']['previous']) }}</span>
                                        @endif
                                    </div>
                                    @if($row['prefix']['limit'] > 0)
                                        <div class="nmsdw-sec">{{ __('limit') }} {{ number_format($row['prefix']['limit']) }}</div>
                                    @endif
                                @else
                                    <span class="nmsdw-muted" title="{{ __('Prefix data unavailable for this peer/context') }}">&mdash;</span>
                                @endif
                            </td>
                        @endif
                        @if($cols['error'])
                            <td class="nmsdw-hide-narrow nmsdw-muted">
                                {{ \Illuminate\Support\Str::limit((string) $peer->bgpPeerLastErrorText, 60) }}
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    @endif
    @include('widgets.partials.nmsdw-result-count', ['shown' => count($rows), 'matched' => $summary['matched'], 'noun' => __('sessions in this mode')])
</div>
