@extends('widgets.settings.base')

@section('form')
    <div class="form-group">
        <label for="title-{{ $id }}" class="control-label">{{ __('Widget title') }}</label>
        <input type="text" class="form-control" name="title" id="title-{{ $id }}"
               placeholder="{{ __('Customer Ports Down') }}" value="{{ $title }}">
    </div>

    @include('widgets.partials.nmsdw-device-groups-field', [
        'id' => $id,
        'selected_device_groups' => $selected_device_groups,
        'placeholder' => __('All accessible devices'),
        'help' => __('Leave empty to include all accessible devices.'),
    ])

    <div class="form-group">
        <label for="match_regex-{{ $id }}" class="control-label">{{ __('Customer port regex') }}</label>
        <input type="text" class="form-control" name="match_regex"
               id="match_regex-{{ $id }}" value="{{ $match_regex }}">
        <span class="help-block">
            {{ __('Matched against interface alias, name and description.') }}
            {{ __('Example') }}: <code>kundeport|customer|kunde</code>
        </span>
    </div>

    <div class="form-group">
        <label for="exclude_regex-{{ $id }}" class="control-label">{{ __('Exclude regex') }}</label>
        <input type="text" class="form-control" name="exclude_regex"
               id="exclude_regex-{{ $id }}" value="{{ $exclude_regex }}">
        <span class="help-block">{{ __('Optional. Example') }}: <code>test|lab|reserve</code></span>
    </div>

    <div class="form-group">
        <label for="min_down_minutes-{{ $id }}" class="control-label">{{ __('Ignore outages shorter than') }}</label>
        <div class="input-group">
            <input type="number" step="1" min="0" max="10080" class="form-control"
                   name="min_down_minutes" id="min_down_minutes-{{ $id }}" value="{{ $min_down_minutes }}">
            <span class="input-group-addon">{{ __('min') }}</span>
        </div>
        <span class="help-block">
            {{ __('Filters out brief blips. Down time is derived from ifLastChange, which is relative to device uptime and resets on reboot; ports with no usable value are always shown.') }}
        </span>
    </div>

    <div class="form-group">
        <label for="time_interval-{{ $id }}" class="control-label">{{ __('Last polled within minutes') }}</label>
        <input type="number" step="1" min="0" max="1440" class="form-control"
               name="time_interval" id="time_interval-{{ $id }}" value="{{ $time_interval }}">
        <span class="help-block">{{ __('Use 0 to disable the last-polled filter.') }}</span>
    </div>

    <div class="form-group">
        <label for="limit-{{ $id }}" class="control-label">{{ __('Maximum rows') }}</label>
        <input type="number" step="1" min="1" max="200" class="form-control"
               name="limit" id="limit-{{ $id }}" value="{{ $limit }}">
    </div>

    <div class="checkbox">
        <label>
            <input type="hidden" name="show_admin_down" value="0">
            <input type="checkbox" name="show_admin_down" value="1" @checked((bool) $show_admin_down)>
            {{ __('Also show administratively shut ports') }}
        </label>
        <span class="help-block">
            {{ __('Off by default: a deliberately shut port is not an outage.') }}
        </span>
    </div>
    <hr>

    @include('widgets.partials.nmsdw-presentation-fields', [
        'id' => $id,
        'layouts' => $layouts,
        'layout' => $layout,
        'density' => $density,
        'accent' => $accent,
        'zebra' => $zebra,
        'show_header' => $show_header,
        'card_min_width' => $card_min_width,
    ])

@endsection

@section('javascript')
    <script type="text/javascript">
        init_select2('#device_groups-{{ $id }}', 'device-group', {});
    </script>
@endsection
