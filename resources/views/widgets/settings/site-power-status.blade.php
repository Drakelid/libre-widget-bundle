@extends('widgets.settings.base')

@section('form')
    <div class="form-group">
        <label for="title-{{ $id }}" class="control-label">{{ __('Widget title') }}</label>
        <input type="text" class="form-control" name="title" id="title-{{ $id }}"
               placeholder="{{ __('Site Power and Battery') }}" value="{{ $title }}">
        <span class="help-block">{{ __('Sets the bar along the top of the widget.') }}</span>
    </div>

    @include('widgets.partials.nmsdw-device-groups-field', [
        'id' => $id,
        'selected_device_groups' => $selected_device_groups,
        'placeholder' => __('All accessible devices'),
        'help' => __('Leave empty to include all accessible devices.'),
    ])

    <div class="form-group">
        <label for="group_by-{{ $id }}" class="control-label">{{ __('Group by') }}</label>
        <select class="form-control" name="group_by" id="group_by-{{ $id }}">
            <option value="device" @selected($group_by === 'device')>{{ __('Device') }}</option>
            <option value="location" @selected($group_by === 'location')>{{ __('Location (site view)') }}</option>
        </select>
        <span class="help-block">
            {{ __('Grouping by location summarises every rectifier and UPS at a site into one row. Devices without a location fall back to their own row.') }}
        </span>
    </div>

    <div class="form-group">
        <label for="show-{{ $id }}" class="control-label">{{ __('Show') }}</label>
        <select class="form-control" name="show" id="show-{{ $id }}">
            <option value="problems" @selected($show === 'problems')>{{ __('Problems only') }}</option>
            <option value="all" @selected($show === 'all')>{{ __('All sites') }}</option>
        </select>
    </div>

    <div class="checkbox">
        <label>
            <input type="hidden" name="battery_only" value="0">
            <input type="checkbox" name="battery_only" value="1" @checked((bool) $battery_only)>
            {{ __('Only devices with battery data') }}
        </label>
        <span class="help-block">
            {{ __('On by default. A charge or runtime sensor is required, so routers and switches that merely report a PSU voltage do not fill the widget.') }}
        </span>
    </div>

    <div class="form-group">
        <label for="min_runtime_minutes-{{ $id }}" class="control-label">{{ __('Minimum battery runtime') }}</label>
        <div class="input-group">
            <input type="number" step="1" min="0" max="10080" class="form-control"
                   name="min_runtime_minutes" id="min_runtime_minutes-{{ $id }}" value="{{ $min_runtime_minutes }}">
            <span class="input-group-addon">{{ __('min') }}</span>
        </div>
        <span class="help-block">{{ __('Critical below this. Use 0 to disable.') }}</span>
    </div>

    <div class="form-group">
        <label for="min_charge_percent-{{ $id }}" class="control-label">{{ __('Minimum battery charge') }}</label>
        <div class="input-group">
            <input type="number" step="1" min="0" max="100" class="form-control"
                   name="min_charge_percent" id="min_charge_percent-{{ $id }}" value="{{ $min_charge_percent }}">
            <span class="input-group-addon">%</span>
        </div>
        <span class="help-block">{{ __('Warning below this.') }}</span>
    </div>

    <div class="form-group">
        <label for="voltage_low-{{ $id }}" class="control-label">{{ __('DC voltage low limit') }}</label>
        <div class="input-group">
            <input type="number" step="any" class="form-control" name="voltage_low"
                   id="voltage_low-{{ $id }}" value="{{ $voltage_low }}" placeholder="{{ __('optional') }}">
            <span class="input-group-addon">V</span>
        </div>
        <span class="help-block">
            {{ __('Optional. For a 48 V plant, around 46 is a reasonable floor. Leave empty to ignore voltage.') }}
        </span>
    </div>

    <div class="form-group">
        <label for="voltage_high-{{ $id }}" class="control-label">{{ __('DC voltage high limit') }}</label>
        <div class="input-group">
            <input type="number" step="any" class="form-control" name="voltage_high"
                   id="voltage_high-{{ $id }}" value="{{ $voltage_high }}" placeholder="{{ __('optional') }}">
            <span class="input-group-addon">V</span>
        </div>
    </div>

    <div class="form-group">
        <label for="limit-{{ $id }}" class="control-label">{{ __('Maximum rows') }}</label>
        <input type="number" step="1" min="1" max="200" class="form-control"
               name="limit" id="limit-{{ $id }}" value="{{ $limit }}">
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
