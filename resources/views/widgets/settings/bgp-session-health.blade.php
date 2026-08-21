@extends('widgets.settings.base')

@section('form')
    <div class="form-group">
        <label for="title-{{ $id }}" class="control-label">{{ __('Widget title') }}</label>
        <input type="text" class="form-control" name="title" id="title-{{ $id }}"
               placeholder="{{ __('BGP Session Health') }}" value="{{ $title }}">
        <span class="help-block">{{ __('Sets the bar along the top of the widget.') }}</span>
    </div>

    @include('widgets.partials.nmsdw-device-groups-field', [
        'id' => $id,
        'selected_device_groups' => $selected_device_groups,
        'placeholder' => __('All accessible devices'),
        'help' => __('Leave empty to include all accessible devices.'),
    ])

    <div class="form-group">
        <label for="show-{{ $id }}" class="control-label">{{ __('Show') }}</label>
        <select class="form-control" name="show" id="show-{{ $id }}">
            <option value="problems" @selected($show === 'problems')>{{ __('Problems only') }}</option>
            <option value="all" @selected($show === 'all')>{{ __('All sessions') }}</option>
            <option value="established_only" @selected($show === 'established_only')>{{ __('Established sessions only') }}</option>
        </select>
        <span class="help-block">
            {{ __('Problems means administratively up but not established, recently re-established, or a sharp drop in accepted prefixes.') }}
        </span>
    </div>

    <div class="form-group">
        <label for="recent_flap_minutes-{{ $id }}" class="control-label">{{ __('Recent flap window / minutes') }}</label>
        <input type="number" step="1" min="0" max="10080" class="form-control"
               name="recent_flap_minutes" id="recent_flap_minutes-{{ $id }}" value="{{ $recent_flap_minutes }}">
        <span class="help-block">
            {{ __('A session established more recently than this is flagged as having just come back. Use 0 to disable.') }}
        </span>
    </div>

    <div class="form-group">
        <label for="limit-{{ $id }}" class="control-label">{{ __('Maximum rows') }}</label>
        <input type="number" step="1" min="1" max="200" class="form-control"
               name="limit" id="limit-{{ $id }}" value="{{ $limit }}">
    </div>

    <div class="form-group">
        <label for="prefix_drop_percent-{{ $id }}" class="control-label">{{ __('Prefix drop warning %') }}</label>
        <input type="number" step="1" min="0" max="100" class="form-control"
               name="prefix_drop_percent" id="prefix_drop_percent-{{ $id }}" value="{{ $prefix_drop_percent }}">
        <span class="help-block">
            {{ __('Warn when accepted prefixes fall by at least this much since the previous poll.') }}
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
