@extends('widgets.settings.base')

@section('form')
    <div class="form-group">
        <label for="title-{{ $id }}" class="control-label">{{ __('Widget title') }}</label>
        <input type="text" class="form-control" name="title" id="title-{{ $id }}"
               placeholder="{{ __('Optical Light Levels') }}" value="{{ $title }}">
        <span class="help-block">{{ __('Sets the bar along the top of the widget.') }}</span>
    </div>

    @include('widgets.partials.nmsdw-device-groups-field', [
        'id' => $id,
        'selected_device_groups' => $selected_device_groups,
        'placeholder' => __('All accessible devices'),
        'help' => __('Leave empty to scan all accessible devices.'),
    ])

    <div class="form-group">
        <label for="sensor_count-{{ $id }}" class="control-label">{{ __('Number of readings to show') }}</label>
        <input type="number" step="1" min="1" max="200" class="form-control"
               name="sensor_count" id="sensor_count-{{ $id }}" value="{{ $sensor_count }}">
    </div>

    <div class="form-group">
        <label for="mode-{{ $id }}" class="control-label">{{ __('Show') }}</label>
        <select class="form-control" name="mode" id="mode-{{ $id }}">
            <option value="worst_margin" @selected($mode === 'worst_margin')>{{ __('Worst margin (RX and TX)') }}</option>
            <option value="rx_only" @selected($mode === 'rx_only')>{{ __('Receive levels only') }}</option>
            <option value="tx_only" @selected($mode === 'tx_only')>{{ __('Transmit levels only') }}</option>
            <option value="all" @selected($mode === 'all')>{{ __('All optical readings') }}</option>
        </select>
        <span class="help-block">
            {{ __('Direction is read from the sensor description; readings that do not identify themselves are only shown in the combined modes.') }}
        </span>
    </div>

    <div class="form-group">
        <label for="warn_margin_db-{{ $id }}" class="control-label">{{ __('Warning margin') }}</label>
        <div class="input-group">
            <input type="number" step="0.1" min="0" max="30" class="form-control"
                   name="warn_margin_db" id="warn_margin_db-{{ $id }}" value="{{ $warn_margin_db }}">
            <span class="input-group-addon">dB</span>
        </div>
        <span class="help-block">
            {{ __('Warn when a reading is within this many dB of its low threshold. Below the threshold is always critical.') }}
        </span>
    </div>

    <div class="form-group">
        <label for="include_regex-{{ $id }}" class="control-label">{{ __('Include regex') }}</label>
        <input type="text" class="form-control" name="include_regex"
               id="include_regex-{{ $id }}" value="{{ $include_regex }}">
        <span class="help-block">
            {{ __('Optional. Matched against hostname, display name, sensor description, sensor type and index.') }}
        </span>
    </div>

    <div class="form-group">
        <label for="exclude_regex-{{ $id }}" class="control-label">{{ __('Exclude regex') }}</label>
        <input type="text" class="form-control" name="exclude_regex"
               id="exclude_regex-{{ $id }}" value="{{ $exclude_regex }}">
        <span class="help-block">{{ __('Optional.') }}</span>
    </div>

    <div class="checkbox">
        <label>
            <input type="hidden" name="only_with_limits" value="0">
            <input type="checkbox" name="only_with_limits" value="1" @checked((bool) $only_with_limits)>
            {{ __('Only show optics that report a low threshold') }}
        </label>
        <span class="help-block">
            {{ __('Without a threshold there is no margin to rank by. Turn this off to audit which optics report no limits.') }}
        </span>
    </div>

    <hr>

    @include('widgets.partials.nmsdw-column-fields', [
        'id' => $id,
        'column_defs' => $column_defs,
        'column_visible' => $column_visible,
    ])

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
