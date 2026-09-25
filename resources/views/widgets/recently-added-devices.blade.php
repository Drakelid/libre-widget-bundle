@include('widgets.partials.nmsdw-style')

<div class="{{ $widget_classes }} nmsdw-recent">
    @if($show_header)
        <div class="nmsdw-head">{{ $heading ?: __('Recently added devices') }}</div>
        <div class="nmsdw-sub">{{ __('newest first') }}</div>
    @endif
    @include('widgets.partials.nmsdw-result-count', ['shown' => $devices->count(), 'matched' => $matched_count, 'noun' => __('devices')])
    @if(in_array($layout, ['cards', 'compact', 'tiles'], true))
        {{-- Alternative layouts share one renderer; each widget only supplies records. --}}
        @php
            $records = collect($devices)->map(fn ($device) => [
                'title' => e($device->displayName()),
                'subtitle' => $device->hardware,
                'value' => $device->inserted ? $device->inserted->diffForHumans(null, true) : __('Unknown'),
                'unit' => $device->inserted ? __('ago') : null,
                'status' => $device->disabled ? 'unknown' : ($device->status ? 'ok' : 'critical'),
                'observed_at' => $device->last_polled,
                'meta' => array_merge(array_values(array_filter([
                    $device->os ? [__('OS'), $device->os] : null,
                    $device->group_names ? [__('Groups'), $device->group_names] : null,
                ])), collect($device->onboarding)->map(fn ($value, $label) => [$label, $value])->values()->all()),
                'href' => \LibreNMS\Util\Url::deviceUrl($device),
            ])->all();
        @endphp

        @include('widgets.partials.nmsdw-records', [
            'records' => $records,
            'layout' => $layout,
            'card_min_width' => $card_min_width,
        ])
    @else
    <div class="table-responsive">
        <table class="table table-hover table-condensed table-striped nmsdw-table">
            <thead>
                <tr>
                    <th>{{ __('Device') }}</th>
                    @if($cols['hardware'])
                        <th class="nmsdw-hide-narrow">{{ __('Hardware') }}</th>
                    @endif
                    @if($cols['os'])
                        <th class="nmsdw-hide-narrow">{{ __('OS') }}</th>
                    @endif
                    <th>{{ __('Added') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($devices as $device)
                    <tr>
                        <td>
                            <span class="label label-{{ $device->disabled ? 'default' : ($device->status ? 'success' : 'danger') }}">&nbsp;</span>
                            <x-device-link :device="$device" />
                            <div class="nmsdw-muted">{{ $device->group_names }}</div>
                            @foreach($device->onboarding as $label => $value)
                                <div class="nmsdw-muted">{{ $label }}: {{ $value }}</div>
                            @endforeach
                            @include('widgets.partials.nmsdw-data-age', ['timestamp' => $device->last_polled])
                        </td>
                        @if($cols['hardware'])
                            <td class="nmsdw-hide-narrow nmsdw-muted">{{ $device->hardware }}</td>
                        @endif
                        @if($cols['os'])
                            <td class="nmsdw-hide-narrow nmsdw-muted">{{ $device->os }}</td>
                        @endif
                        <td class="nmsdw-muted" title="{{ $device->inserted }}">
                            {{ $device->inserted ? $device->inserted->diffForHumans() : __('Unknown') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            @include('widgets.partials.nmsdw-empty', ['message' => __('No devices found.')])
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endif
</div>
