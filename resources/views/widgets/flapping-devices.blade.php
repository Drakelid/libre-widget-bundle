@include('widgets.partials.nmsdw-style')

<div class="nmsdw-widget nmsdw-flapping">
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

    @if($rows->isEmpty())
        @include('widgets.partials.nmsdw-empty', [
            'message' => __('Nothing flapping.'),
            'hint' => __('No device or port changed state at least :min times in the last :hours hours.', [
                'min' => $min_changes,
                'hours' => $lookback_hours,
            ]),
        ])
    @else
        <table class="nmsdw-table">
            <thead>
                <tr>
                    <th>{{ __('Device') }}</th>
                    <th>{{ __('Item') }}</th>
                    <th class="nmsdw-nowrap">{{ __('Changes') }}</th>
                    <th class="nmsdw-nowrap">{{ __('State') }}</th>
                    <th class="nmsdw-hide-narrow nmsdw-nowrap">{{ __('Last') }}</th>
                    <th class="nmsdw-hide-narrow">{{ __('Message') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td class="nmsdw-strong">{{ $row->device_name }}</td>
                        <td>
                            @if($row->item_type === 'port')
                                <span class="nmsdw-sec">{{ __('Port') }}</span>
                                {{ $row->port_name }}
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
                        <td class="nmsdw-nowrap">{{ $row->state }}</td>
                        <td class="nmsdw-hide-narrow nmsdw-muted nmsdw-nowrap"
                            title="{{ $row->last_change }}">
                            {{ \Carbon\Carbon::parse($row->last_change)->diffForHumans(null, true) }}
                        </td>
                        <td class="nmsdw-hide-narrow nmsdw-muted">{{ $row->short_message }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
