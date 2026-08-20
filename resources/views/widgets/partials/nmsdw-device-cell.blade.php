{{--
    Device link that tolerates a missing device.

    core's <x-device-link> calls $device->displayName() unguarded, so passing null
    fatals the whole widget. A port or sensor whose device row has gone away is rare
    but not impossible, and one bad row should not blank the dashboard.

    Expects $linkDevice.
--}}
@if($linkDevice)
    <x-device-link :device="$linkDevice" />
@else
    <span class="nmsdw-muted">{{ __('Unknown device') }}</span>
@endif
