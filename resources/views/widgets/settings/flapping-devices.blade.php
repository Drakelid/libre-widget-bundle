@extends('widgets.settings.base')

{{--
    Note: this form deliberately does NOT declare its own refresh field. The parent
    template already renders one. The original widget declared a second, producing two
    inputs sharing name="refresh" and id="refresh".

    Every id is also suffixed with the widget id; the original used bare ids, which
    collided whenever two copies of this widget shared a dashboard.
--}}

@section('form')
    <div class="form-group">
        <label for="title-{{ $id }}" class="control-label">{{ __('Widget title') }}</label>
        <input type="text" class="form-control" name="title" id="title-{{ $id }}"
               placeholder="{{ __('Flapping Devices / Unstable Links') }}" value="{{ $title }}">
    </div>

    <div class="form-group">
        <label for="lookback_hours-{{ $id }}" class="control-label">{{ __('Lookback window / hours') }}</label>
        <input type="number" step="1" min="1" max="168" class="form-control"
               name="lookback_hours" id="lookback_hours-{{ $id }}" value="{{ $lookback_hours }}">
        <span class="help-block">{{ __('Example: 24 = last 24 hours. Max 168 hours.') }}</span>
    </div>

    <div class="form-group">
        <label for="min_changes-{{ $id }}" class="control-label">{{ __('Minimum changes') }}</label>
        <input type="number" step="1" min="2" max="100" class="form-control"
               name="min_changes" id="min_changes-{{ $id }}" value="{{ $min_changes }}">
        <span class="help-block">{{ __('Only show devices or ports with at least this many state changes.') }}</span>
    </div>

    <div class="form-group">
        <label for="limit-{{ $id }}" class="control-label">{{ __('Maximum rows') }}</label>
        <input type="number" step="1" min="1" max="100" class="form-control"
               name="limit" id="limit-{{ $id }}" value="{{ $limit }}">
    </div>

    <div class="form-group">
        <label for="show_type-{{ $id }}" class="control-label">{{ __('Show') }}</label>
        <select class="form-control" name="show_type" id="show_type-{{ $id }}">
            <option value="all" @selected($show_type === 'all')>{{ __('Devices and ports') }}</option>
            <option value="devices" @selected($show_type === 'devices')>{{ __('Devices only') }}</option>
            <option value="ports" @selected($show_type === 'ports')>{{ __('Ports only') }}</option>
        </select>
    </div>

    <div class="form-group">
        <label for="device_group-{{ $id }}" class="control-label">{{ __('Device group') }}</label>
        <select class="form-control" name="device_group" id="device_group-{{ $id }}"
                data-placeholder="{{ __('All accessible devices') }}">
            @if($device_group)
                <option value="{{ $device_group->id }}" selected>{{ $device_group->name }}</option>
            @endif
        </select>
        <span class="help-block">{{ __('Leave empty for all accessible devices.') }}</span>
    </div>

    <script type="text/javascript">
        (function () {
            var selector = '#device_group-{{ $id }}';

            if (typeof init_select2 === 'function') {
                init_select2(selector, 'nmsdashwidgets-device-groups', {}, null, {allowClear: true});
            }
        })();
    </script>
@endsection
