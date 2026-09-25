@extends('widgets.settings.base')

@section('form')
    <div class="form-group">
        <label for="title-{{ $id }}" class="control-label">{{ __('Widget title') }}</label>
        <input type="text" class="form-control" name="title" id="title-{{ $id }}"
               placeholder="{{ __('Recently Added Devices') }}" value="{{ $title }}">
        <span class="help-block">{{ __('Sets the bar along the top of the widget.') }}</span>
    </div>

    <div class="form-group">
        <label for="device_count-{{ $id }}" class="control-label">{{ __('Number of devices to show') }}</label>
        <input type="number" step="1" min="1" max="50" class="form-control"
               name="device_count" id="device_count-{{ $id }}" value="{{ $device_count }}">
    </div>
    @include('widgets.partials.nmsdw-device-groups-field', ['id' => $id, 'selected_device_groups' => $selected_device_groups, 'help' => __('Leave empty to include all accessible devices.')])
    <div class="form-group">
        <label for="added_within_days-{{ $id }}">{{ __('Added within days (0 = all time)') }}</label>
        <input class="form-control" type="number" min="0" max="3650" name="added_within_days" id="added_within_days-{{ $id }}" value="{{ $added_within_days }}">
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
