@extends('widgets.settings.base')

@section('form')
    <div class="form-group">
        <label for="title-{{ $id }}" class="control-label">{{ __('Widget title') }}</label>
        <input type="text" class="form-control" name="title" id="title-{{ $id }}"
               placeholder="{{ __('Uplink Utilization Overview') }}" value="{{ $title }}">
    </div>

    @include('widgets.partials.nmsdw-device-groups-field', [
        'id' => $id,
        'selected_device_groups' => $selected_device_groups,
        'placeholder' => __('All accessible devices'),
        'help' => __('Select one or more device groups. Leave empty to scan all accessible devices.'),
    ])

    <div class="form-group">
        <label for="uplink_regex-{{ $id }}" class="control-label">{{ __('Uplink match regex') }}</label>
        <input type="text" class="form-control" name="uplink_regex" id="uplink_regex-{{ $id }}"
               value="{{ $uplink_regex }}">
        <span class="help-block">
            {{ __('Matched against interface name, description, and alias.') }}
            {{ __('Example') }}: <code>uplink|wan|core|transport|trunk</code>
        </span>
    </div>

    <div class="form-group">
        <label for="exclude_regex-{{ $id }}" class="control-label">{{ __('Exclude regex') }}</label>
        <input type="text" class="form-control" name="exclude_regex" id="exclude_regex-{{ $id }}"
               value="{{ $exclude_regex }}">
        <span class="help-block">
            {{ __('Optional.') }} {{ __('Example') }}: <code>backup|disabled|unused</code>
        </span>
    </div>

    <div class="form-group">
        <label for="top_count-{{ $id }}" class="control-label">{{ __('Number of uplinks to show') }}</label>
        <input type="number" step="1" min="1" max="100" class="form-control"
               name="top_count" id="top_count-{{ $id }}" value="{{ $top_count }}">
    </div>

    <div class="form-group">
        <label for="time_interval-{{ $id }}" class="control-label">{{ __('Last polled within minutes') }}</label>
        <input type="number" step="1" min="1" max="1440" class="form-control"
               name="time_interval" id="time_interval-{{ $id }}" value="{{ $time_interval }}">
    </div>

    <div class="form-group">
        <label for="warning_threshold-{{ $id }}" class="control-label">{{ __('Warning threshold %') }}</label>
        <input type="number" step="1" min="1" max="100" class="form-control"
               name="warning_threshold" id="warning_threshold-{{ $id }}" value="{{ $warning_threshold }}">
        <span class="help-block">{{ __('Must be lower than the critical threshold.') }}</span>
    </div>

    <div class="form-group">
        <label for="critical_threshold-{{ $id }}" class="control-label">{{ __('Critical threshold %') }}</label>
        <input type="number" step="1" min="1" max="100" class="form-control"
               name="critical_threshold" id="critical_threshold-{{ $id }}" value="{{ $critical_threshold }}">
    </div>

    <div class="checkbox">
        <label>
            <input type="hidden" name="show_graphs" value="0">
            <input type="checkbox" name="show_graphs" value="1" @checked((bool) $show_graphs)>
            {{ __('Show mini graphs') }}
        </label>
    </div>

    <div class="checkbox">
        <label>
            <input type="hidden" name="show_device_group" value="0">
            <input type="checkbox" name="show_device_group" value="1" @checked((bool) $show_device_group)>
            {{ __('Show device group column') }}
        </label>
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
