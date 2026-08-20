@extends('widgets.settings.base')

@section('form')
    <div class="form-group">
        <label for="title-{{ $id }}" class="control-label">{{ __('Widget title') }}</label>
        <input type="text" class="form-control" name="title" id="title-{{ $id }}"
               placeholder="{{ __('Device Group Down Count') }}" value="{{ $title }}">
    </div>

    @include('widgets.partials.nmsdw-device-groups-field', [
        'id' => $id,
        'selected_device_groups' => $selected_device_groups,
        'placeholder' => __('Select device groups'),
        'help' => __('Select one or more groups. The widget shows down count per selected group. With nothing selected the widget stays empty.'),
    ])

    <div class="form-group">
        <label for="display_mode-{{ $id }}" class="control-label">{{ __('Display mode') }}</label>
        <select class="form-control" name="display_mode" id="display_mode-{{ $id }}">
            <option value="auto" @selected($display_mode === 'auto')>{{ __('Auto') }}</option>
            <option value="cards" @selected($display_mode === 'cards')>{{ __('Cards') }}</option>
            <option value="list" @selected($display_mode === 'list')>{{ __('List') }}</option>
            <option value="compact" @selected($display_mode === 'compact')>{{ __('Compact') }}</option>
            <option value="summary" @selected($display_mode === 'summary')>{{ __('Summary') }}</option>
        </select>
        <span class="help-block">
            {{ __('Auto works well for most dashboards. List is best for narrow widgets. Summary is best for very small widgets.') }}
        </span>
    </div>

    <div class="form-group">
        <label for="density-{{ $id }}" class="control-label">{{ __('Density') }}</label>
        <select class="form-control" name="density" id="density-{{ $id }}">
            <option value="comfortable" @selected($density === 'comfortable')>{{ __('Comfortable') }}</option>
            <option value="compact" @selected($density === 'compact')>{{ __('Compact') }}</option>
        </select>
    </div>

    <div class="form-group">
        <label for="card_min_width-{{ $id }}" class="control-label">{{ __('Minimum card width') }}</label>
        <input type="number" step="1" min="120" max="320" class="form-control"
               name="card_min_width" id="card_min_width-{{ $id }}" value="{{ $card_min_width }}">
        <span class="help-block">{{ __('Used by card layouts. Recommended: 150-220.') }}</span>
    </div>

    <div class="form-group">
        <label for="show_header-{{ $id }}" class="control-label">{{ __('Show header') }}</label>
        <select class="form-control" name="show_header" id="show_header-{{ $id }}">
            <option value="1" @selected($show_header)>{{ __('Yes') }}</option>
            <option value="0" @selected(! $show_header)>{{ __('No') }}</option>
        </select>
    </div>

    <div class="form-group">
        <label for="show_total-{{ $id }}" class="control-label">{{ __('Show total down count') }}</label>
        <select class="form-control" name="show_total" id="show_total-{{ $id }}">
            <option value="1" @selected($show_total)>{{ __('Yes') }}</option>
            <option value="0" @selected(! $show_total)>{{ __('No') }}</option>
        </select>
        <span class="help-block">{{ __('Used when multiple device groups are selected.') }}</span>
    </div>

    <div class="form-group">
        <label for="show_group_totals-{{ $id }}" class="control-label">{{ __('Show group totals') }}</label>
        <select class="form-control" name="show_group_totals" id="show_group_totals-{{ $id }}">
            <option value="1" @selected($show_group_totals)>{{ __('Yes') }}</option>
            <option value="0" @selected(! $show_group_totals)>{{ __('No') }}</option>
        </select>
    </div>

    <div class="form-group">
        <label for="exclude_ignored_disabled-{{ $id }}" class="control-label">{{ __('Ignore disabled and ignored devices') }}</label>
        <select class="form-control" name="exclude_ignored_disabled" id="exclude_ignored_disabled-{{ $id }}">
            <option value="1" @selected($exclude_ignored_disabled)>{{ __('Yes') }}</option>
            <option value="0" @selected(! $exclude_ignored_disabled)>{{ __('No') }}</option>
        </select>
        <span class="help-block">{{ __('When No, every device with a down status is counted.') }}</span>
    </div>

    <div class="form-group">
        <label for="background_color-{{ $id }}" class="control-label">{{ __('Alert background colour') }}</label>
        <input type="color" class="form-control" name="background_color"
               id="background_color-{{ $id }}" value="{{ $background_color }}">
        <span class="help-block">{{ __('Used for the total banner when devices are down.') }}</span>
    </div>

    <div class="form-group">
        <label for="text_color-{{ $id }}" class="control-label">{{ __('Alert text colour') }}</label>
        <input type="color" class="form-control" name="text_color"
               id="text_color-{{ $id }}" value="{{ $text_color }}">
    </div>
@endsection

@section('javascript')
    <script type="text/javascript">
        init_select2('#device_groups-{{ $id }}', 'device-group', {});
    </script>
@endsection
