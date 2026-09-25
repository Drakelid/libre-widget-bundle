@include('widgets.partials.nmsdw-style')

<div class="{{ $widget_classes }} nmsdw-flapping">
    @if($show_header)
        <div class="nmsdw-head">{{ $heading ?: __('Flapping devices and links') }}</div>
        <div class="nmsdw-sub">
            {{ __('last :h hours', ['h' => $lookback_hours]) }} &middot;
            {{ __('at least :n changes', ['n' => $min_changes]) }}
        </div>
    @endif
    <div class="nmsdw-tiles">
        @include('widgets.partials.nmsdw-tile', [
            'value' => $summary['total_changes'],
            'label' => __('State changes'),
        ])
        @include('widgets.partials.nmsdw-tile', [
            'value' => $summary['devices'],
            'label' => __('Devices'),
        ])
        @include('widgets.partials.nmsdw-tile', [
            'value' => $summary['ports'],
            'label' => __('Ports'),
        ])
        @include('widgets.partials.nmsdw-tile', [
            'value' => $summary['last_change'] ? \Carbon\Carbon::parse($summary['last_change'])->diffForHumans(null, true) : '-',
            'label' => __('Last change'),
        ])
    </div>
    @include('widgets.partials.nmsdw-result-count', ['shown' => $rows->count(), 'matched' => $summary['matched'], 'noun' => __('flapping items')])
    <div class="nmsdw-note">{{ __('Summary covers every matching item in the selected time window.') }}</div>

    @if($rows->isEmpty())
        @include('widgets.partials.nmsdw-empty', [
            'message' => __('Nothing flapping.'),
            'hint' => __('No device or port changed state at least :min times in the last :hours hours.', [
                'min' => $min_changes,
                'hours' => $lookback_hours,
            ]),
        ])
    @else
        @if(in_array($layout, ['cards', 'compact', 'tiles'], true))
        {{-- Alternative layouts share one renderer; each widget only supplies records. --}}
        @php
            $records = collect($rows)->map(fn ($r) => [
                'title' => e($r->device_name),
                'subtitle' => $r->item_type === 'port' ? $r->port_name : __('Device'),
                'value' => $r->changes,
                'unit' => __('state changes'),
                'status' => $r->severity,
                'meta' => [
                    [__('Current polled state'), $r->current_state],
                    [__('Last event state'), $r->state],
                    [__('Last'), \Carbon\Carbon::parse($r->last_change)->diffForHumans(null, true)],
                ],
                'href' => $r->item_url,
                'observed_at' => $r->observed_at,
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
                    <th>{{ __('Item') }}</th>
                    <th class="nmsdw-nowrap">{{ __('Changes') }}</th>
                    @if($cols['state'])
                        <th class="nmsdw-nowrap">{{ __('State') }}</th>
                    @endif
                    @if($cols['last'])
                        <th class="nmsdw-hide-narrow nmsdw-nowrap">{{ __('Last') }}</th>
                    @endif
                    @if($cols['message'])
                        <th class="nmsdw-hide-narrow">{{ __('Message') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td class="nmsdw-strong"><a href="{{ $row->device_url }}">{{ $row->device_name }}</a>
                            @include('widgets.partials.nmsdw-data-age', ['timestamp' => $row->observed_at])
                        </td>
                        <td>
                            @if($row->item_type === 'port')
                                <span class="nmsdw-sec">{{ __('Port') }}</span>
                                <a href="{{ $row->item_url }}">{{ $row->port_name }}</a>
                            @else
                                <span class="nmsdw-sec">{{ __('Device') }}</span>
                            @endif
                        </td>
                        <td>
                            @include('widgets.partials.nmsdw-pill', [
                                'status' => $row->severity,
                                'label' => $row->changes,
                            ])
                        </td>
                        @if($cols['state'])
                            <td class="nmsdw-nowrap">{{ $row->current_state }}<br><small>{{ __('Last event') }}: {{ $row->state }}</small></td>
                        @endif
                        @if($cols['last'])
                            <td class="nmsdw-hide-narrow nmsdw-muted nmsdw-nowrap"
                                title="{{ $row->last_change }}">
                                {{ \Carbon\Carbon::parse($row->last_change)->diffForHumans(null, true) }}
                            </td>
                        @endif
                        @if($cols['message'])
                            <td class="nmsdw-hide-narrow nmsdw-muted">{{ $row->short_message }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
        <details>
            <summary>{{ __('Recent transitions (up to 8 per displayed item)') }}</summary>
            @foreach($rows as $row)
                <div>
                    <a href="{{ $row->item_url }}">{{ $row->device_name }} {{ $row->port_name }}</a>
                    <a href="{{ $row->event_url }}">{{ __('Event log') }}</a>
                    <ol>
                        @foreach($row->timeline as $event)
                            <li title="{{ $event->message }}">{{ $event->datetime }} &mdash; {{ $event->state }}</li>
                        @endforeach
                    </ol>
                </div>
            @endforeach
        </details>
    @endif
</div>
