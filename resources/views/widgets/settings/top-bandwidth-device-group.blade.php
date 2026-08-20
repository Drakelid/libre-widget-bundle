@extends('widgets.settings.base')

@section('form')
    <div class="form-group">
        <label for="title-{{ $id }}" class="control-label">{{ __('Widget title') }}</label>
        <input type="text" class="form-control" name="title" id="title-{{ $id }}"
               placeholder="{{ __('Top Bandwidth Usage by Device Group') }}" value="{{ $title }}">
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
        <select class="form-control" name="interface_filter" id="interface_filter-{{ $id }}">
            <option value="">{{ __('All ports') }}</option>
            @foreach($interface_types as $type)
                <option value="{{ $type }}" @selected($interface_filter === $type)>{{ $type }}</option>
            @endforeach
        </select>
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
            <input type="hidden" name="show_utilisation" value="0">
            <input type="checkbox" name="show_utilisation" value="1" @checked((bool) $show_utilisation)>
            {{ __('Show utilisation percentage') }}
        </label>
    </div>
@endsection
