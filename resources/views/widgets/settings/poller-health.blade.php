@extends('widgets.settings.base')

@section('form')
    <div class="form-group">
        <label for="title-{{ $id }}" class="control-label">{{ __('Widget title') }}</label>
        <input type="text" class="form-control" name="title" id="title-{{ $id }}"
               placeholder="{{ __('Poller Health') }}" value="{{ $title }}">
        <span class="help-block">{{ __('Sets the bar along the top of the widget.') }}</span>
    </div>

    @include('widgets.partials.nmsdw-device-groups-field', [
        'id' => $id,
        'selected_device_groups' => $selected_device_groups,
        'placeholder' => __('All accessible devices'),
        'help' => __('Leave empty to include all accessible devices.'),
    ])

    <div class="form-group">
        <label for="stale_minutes-{{ $id }}" class="control-label">{{ __('Consider stale after') }}</label>
        <div class="input-group">
            <input type="number" step="1" min="1" max="10080" class="form-control"
                   name="stale_minutes" id="stale_minutes-{{ $id }}" value="{{ $stale_minutes }}">
            <span class="input-group-addon">{{ __('min') }}</span>
        </div>
        <span class="help-block">
            {{ __('Set this above your polling interval. With five minute polling, 15 allows two missed cycles before flagging.') }}
        </span>
    </div>

    <div class="form-group">
        <label for="limit-{{ $id }}" class="control-label">{{ __('Maximum rows') }}</label>
        <input type="number" step="1" min="1" max="200" class="form-control"
               name="limit" id="limit-{{ $id }}" value="{{ $limit }}">
    </div>

    <div class="checkbox">
        <label>
            <input type="hidden" name="show_pollers" value="0">
            <input type="checkbox" name="show_pollers" value="1" @checked((bool) $show_pollers)>
            {{ __('Show poller nodes and their last check-in') }}
        </label>
    </div>

    <div class="checkbox">
        <label>
            <input type="hidden" name="ignore_disabled" value="0">
            <input type="checkbox" name="ignore_disabled" value="1" @checked((bool) $ignore_disabled)>
            {{ __('Ignore disabled and ignored devices') }}
        </label>
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
