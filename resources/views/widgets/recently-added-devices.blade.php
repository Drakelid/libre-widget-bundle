@include('widgets.partials.nmsdw-style')

<div class="nmsdw-widget nmsdw-recent">
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
</div>
