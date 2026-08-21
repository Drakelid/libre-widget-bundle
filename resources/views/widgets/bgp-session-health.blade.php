@include('widgets.partials.nmsdw-style')

<div class="nmsdw-widget nmsdw-bgp">
    <div class="nmsdw-head">{{ __('BGP session health') }}</div>
    <div class="nmsdw-sub">{{ $group_label }}</div>

    <div class="nmsdw-tiles">
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['total'], 'label' => __('Sessions')])
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['established'], 'label' => __('Established')])
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['down'], 'label' => __('Down')])
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['recent'], 'label' => __('Recently up')])
        @include('widgets.partials.nmsdw-tile', ['value' => $summary['admin_down'], 'label' => __('Admin down')])
    </div>

    @if(empty($rows))
        @include('widgets.partials.nmsdw-empty', [
            'message' => $summary['total'] === 0
                ? __('No BGP peers found.')
                : __('All BGP sessions are healthy.'),
            'hint' => $summary['total'] === 0
                ? __('The bgp-peers discovery module may be disabled, or these devices do not run BGP.')
                : null,
        ])
    @else
        <table class="nmsdw-table">
            <thead>
                <tr>
                    <th>{{ __('Device') }}</th>
                    <th>{{ __('Peer') }}</th>
                    <th class="nmsdw-nowrap">{{ __('State') }}</th>
                    <th class="nmsdw-hide-narrow nmsdw-nowrap">{{ __('Uptime') }}</th>
                    @if($show_prefixes)
                        <th class="nmsdw-hide-narrow nmsdw-nowrap">{{ __('Prefixes') }}</th>
                    @endif
                    <th class="nmsdw-hide-narrow">{{ __('Last error') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    @php($peer = $row['peer'])
                    <tr>
                        <td class="nmsdw-strong">
                            @include('widgets.partials.nmsdw-device-cell', ['linkDevice' => $peer->device])
                        </td>
                        <td>
                            <span class="nmsdw-strong">{{ $peer->bgpPeerIdentifier }}</span>
                            <span class="nmsdw-sec">
                                AS{{ $peer->bgpPeerRemoteAs }}@if($peer->astext) &middot; {{ $peer->astext }}@endif
                            </span>
                            @if($peer->bgpPeerDescr)
                                <span class="nmsdw-sec">{{ $peer->bgpPeerDescr }}</span>
                            @endif
                        </td>
                        <td class="nmsdw-nowrap">
                            @include('widgets.partials.nmsdw-pill', [
                                'status' => $row['status'],
                                'label' => $row['admin_up'] ? $peer->bgpPeerState : __('shut'),
                            ])
                            @if($row['recent'])
                                <span class="nmsdw-sec">{{ __('just re-established') }}</span>
                            @endif
                        </td>
                        <td class="nmsdw-hide-narrow nmsdw-muted nmsdw-nowrap">
                            @if($row['uptime_seconds'] > 0)
                                {{ \Carbon\CarbonInterval::seconds($row['uptime_seconds'])->cascade()->forHumans(['short' => true, 'parts' => 2]) }}
                            @else
                                &mdash;
                            @endif
                        </td>
                        @if($show_prefixes)
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
                                    <span class="nmsdw-muted">&mdash;</span>
                                @endif
                            </td>
                        @endif
                        <td class="nmsdw-hide-narrow nmsdw-muted">
                            {{ \Illuminate\Support\Str::limit((string) $peer->bgpPeerLastErrorText, 60) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
