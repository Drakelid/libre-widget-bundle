@extends('widgets.settings.base')

@section('form')
    <div class="form-group">
        <label for="title-{{ $id }}" class="control-label">{{ __('Widget title') }}</label>
        <input type="text" class="form-control" name="title" id="title-{{ $id }}"
               placeholder="{{ __('Top Bandwidth Usage by Device Group') }}" value="{{ $title }}">
        <span class="help-block">{{ __('Sets the bar along the top of the widget.') }}</span>
    </div>

    <div class="form-group">
        <label for="top_count-{{ $id }}" class="control-label">{{ __('Number of ports') }}</label>
        <input type="number" step="1" min="1" max="50" class="form-control"
               name="top_count" id="top_count-{{ $id }}" value="{{ $top_count }}">
    </div>

    <div class="form-group">
        <label for="time_interval-{{ $id }}" class="control-label">{{ __('Last polled within minutes') }}</label>
        <input type="number" step="1" min="1" max="1440" class="form-control"
               name="time_interval" id="time_interval-{{ $id }}" value="{{ $time_interval }}">
    </div>

    @include('widgets.partials.nmsdw-device-groups-field', [
        'id' => $id,
        'selected_device_groups' => $selected_device_groups,
        'placeholder' => __('All device groups'),
        'help' => __('Leave empty to show all accessible devices.'),
    ])

    <div class="form-group">
        <label for="interface_filter-{{ $id }}" class="control-label">{{ __('Interface type') }}</label>
        <select class="form-control" name="interface_filter" id="interface_filter-{{ $id }}"
                data-placeholder="{{ __('All ports') }}">
            @if($interface_filter)
                <option value="{{ $interface_filter }}" selected>{{ $interface_filter }}</option>
            @endif
        </select>
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
        {{-- Core's port-field source looks up distinct ifType values on demand, so the
             settings form never runs a DISTINCT over the whole ports table. --}}
        init_select2('#interface_filter-{{ $id }}', 'port-field',
            {limit: 100, field: 'ifType'}, '{{ $interface_filter ?: '' }}');
    </script>
@endsection
