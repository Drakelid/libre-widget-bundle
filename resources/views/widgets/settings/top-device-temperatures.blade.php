@extends('widgets.settings.base')

@section('form')
    <div class="form-group">
        <label for="title-{{ $id }}" class="control-label">{{ __('Widget title') }}</label>
        <input type="text" class="form-control" name="title" id="title-{{ $id }}"
               placeholder="{{ __('Top Device Temperatures') }}" value="{{ $title }}">
        <span class="help-block">{{ __('Sets the bar along the top of the widget.') }}</span>
    </div>

    <div class="form-group">
        <label for="device_count-{{ $id }}" class="control-label">{{ __('Number of devices') }}</label>
        <input type="number" step="1" min="1" max="100" class="form-control"
               name="device_count" id="device_count-{{ $id }}" value="{{ $device_count }}">
        <span class="help-block">{{ __('One row per device, showing that device\'s hottest sensor.') }}</span>
    </div>

    <div class="form-group">
        <label for="time_interval-{{ $id }}" class="control-label">{{ __('Last polled within minutes') }}</label>
        <input type="number" step="1" min="0" max="10080" class="form-control"
               name="time_interval" id="time_interval-{{ $id }}" value="{{ $time_interval }}">
        <span class="help-block">{{ __('Use 0 to disable the last-polled filter.') }}</span>
    </div>

    <div class="form-group">
        <label for="only_up-{{ $id }}" class="control-label">{{ __('Device filter') }}</label>
        <select class="form-control" name="only_up" id="only_up-{{ $id }}">
            <option value="1" @selected($only_up)>{{ __('Only up, enabled, non-ignored devices') }}</option>
            <option value="0" @selected(! $only_up)>{{ __('All devices') }}</option>
        </select>
    </div>

    @include('widgets.partials.nmsdw-device-groups-field', [
        'id' => $id,
        'selected_device_groups' => $selected_device_groups,
        'placeholder' => __('All device groups'),
        'help' => __('Select one or more device groups. Leave empty to include all device groups. Devices matching any selected group are included.'),
    ])

    <div class="form-group">
        <label for="warn_temp-{{ $id }}" class="control-label">{{ __('Warning temperature') }}</label>
        <div class="input-group">
            <input type="number" step="any" class="form-control"
                   name="warn_temp" id="warn_temp-{{ $id }}" value="{{ $warn_temp }}">
            <span class="input-group-addon">&deg;C</span>
        </div>
        <span class="help-block">{{ __('Temperatures equal to or above this value are shown as warning.') }}</span>
    </div>

    <div class="form-group">
        <label for="limit_temp-{{ $id }}" class="control-label">{{ __('Critical / limit temperature') }}</label>
        <div class="input-group">
            <input type="number" step="any" class="form-control"
                   name="limit_temp" id="limit_temp-{{ $id }}" value="{{ $limit_temp }}">
            <span class="input-group-addon">&deg;C</span>
        </div>
        <span class="help-block">{{ __('Temperatures equal to or above this value are shown as critical.') }}</span>
    </div>

    <div class="form-group">
        <label for="sensor_include_regex-{{ $id }}" class="control-label">{{ __('Include sensor regex') }}</label>
        <input type="text" class="form-control" name="sensor_include_regex"
               id="sensor_include_regex-{{ $id }}" value="{{ $sensor_include_regex }}">
        <span class="help-block">
            {{ __('Optional. If set, only temperature sensors matching this regex are included. Matched against hostname, display name, sensor description, sensor type, and sensor index.') }}
        </span>
    </div>

    <div class="form-group">
        <label for="sensor_exclude_regex-{{ $id }}" class="control-label">{{ __('Exclude sensor regex') }}</label>
        <input type="text" class="form-control" name="sensor_exclude_regex"
               id="sensor_exclude_regex-{{ $id }}" value="{{ $sensor_exclude_regex }}"
               placeholder="module|transceiver|sfp|qsfp|optic">
        <span class="help-block">{{ __('Optional. Temperature sensors matching this regex are excluded.') }}</span>
    </div>

    <div class="form-group">
        <label for="include_module_sensors-{{ $id }}" class="control-label">{{ __('Temperature source') }}</label>
        <select class="form-control" name="include_module_sensors" id="include_module_sensors-{{ $id }}">
            <option value="0" @selected(! $include_module_sensors)>{{ __('Device / chassis temperature sensors only') }}</option>
            <option value="1" @selected($include_module_sensors)>{{ __('All temperature sensors') }}</option>
        </select>
        <span class="help-block">
            {{ __('Leave this on device/chassis only to avoid SFP, port, and interface module temperatures being shown as device temperatures. Regex filters are applied after this option.') }}
        </span>
    </div>
    <hr>

    @include('widgets.partials.nmsdw-presentation-fields', [
        'id' => $id,
        'layouts' => $layouts,
        'heading' => $heading,
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
