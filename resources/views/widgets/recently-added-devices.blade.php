@include('widgets.partials.nmsdw-style')

<div class="{{ $widget_classes }} nmsdw-recent">
    @if($show_header)
        <div class="nmsdw-head">{{ __('Recently added devices') }}</div>
        <div class="nmsdw-sub">{{ __('newest first') }}</div>
    @endif
    @if(in_array($layout, ['cards', 'compact', 'tiles'], true))
        {{-- Alternative layouts share one renderer; each widget only supplies records. --}}
        @php
            $records = collect($devices)->map(fn ($device) => [
                'title' => e($device->displayName()),
                'subtitle' => $device->hardware,
                'value' => $device->inserted ? $device->inserted->diffForHumans(null, true) : __('Unknown'),
                'unit' => $device->inserted ? __('ago') : null,
                'status' => $device->disabled ? 'unknown' : ($device->status ? 'ok' : 'critical'),
                'meta' => array_values(array_filter([
                    $device->os ? [__('OS'), $device->os] : null,
                ])),
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
                    <th class="nmsdw-hide-narrow">{{ __('Hardware') }}</th>
                    <th class="nmsdw-hide-narrow">{{ __('OS') }}</th>
                    <th>{{ __('Added') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($devices as $device)
                    <tr>
                        <td>
                            <span class="label label-{{ $device->disabled ? 'default' : ($device->status ? 'success' : 'danger') }}">&nbsp;</span>
                            <x-device-link :device="$device" />
                        </td>
                        <td class="nmsdw-hide-narrow nmsdw-muted">{{ $device->hardware }}</td>
                        <td class="nmsdw-hide-narrow nmsdw-muted">{{ $device->os }}</td>
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
