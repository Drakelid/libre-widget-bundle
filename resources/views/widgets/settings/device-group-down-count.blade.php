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
            <option value="auto" @selected($display_mode === 'auto')>{{ __('Auto (follow widget size)') }}</option>
            <option value="list" @selected($display_mode === 'list')>{{ __('List — name, status, count and totals') }}</option>
            <option value="cards" @selected($display_mode === 'cards')>{{ __('Cards — a tile per group with a health bar') }}</option>
            <option value="bars" @selected($display_mode === 'bars')>{{ __('Bars — ranked, bar length is the share down') }}</option>
            <option value="tiles" @selected($display_mode === 'tiles')>{{ __('Tiles — dense colour squares, good for wall displays') }}</option>
            <option value="compact" @selected($display_mode === 'compact')>{{ __('Compact — one dense line per group') }}</option>
            <option value="summary" @selected($display_mode === 'summary')>{{ __('Summary — the total only') }}</option>
        </select>
        <span class="help-block">
            {{ __('Auto picks summary, list or cards from the widget width. Bars compare groups by the proportion that is down, which a raw count hides: 2 of 2 is an outage, 22 of 500 is not.') }}
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
        <label for="sort-{{ $id }}" class="control-label">{{ __('Sort groups by') }}</label>
        <select class="form-control" name="sort" id="sort-{{ $id }}">
            <option value="selection" @selected($sort === 'selection')>{{ __('The order I selected them') }}</option>
            <option value="most_down" @selected($sort === 'most_down')>{{ __('Most devices down') }}</option>
            <option value="percent" @selected($sort === 'percent')>{{ __('Largest share of the group down') }}</option>
            <option value="name" @selected($sort === 'name')>{{ __('Name') }}</option>
        </select>
    </div>

    <div class="form-group">
        <label for="hide_healthy-{{ $id }}" class="control-label">{{ __('Hide healthy groups') }}</label>
        <select class="form-control" name="hide_healthy" id="hide_healthy-{{ $id }}">
            <option value="0" @selected(! $hide_healthy)>{{ __('No') }}</option>
            <option value="1" @selected($hide_healthy)>{{ __('Yes') }}</option>
        </select>
        <span class="help-block">
            {{ __('Totals in the header and banner always cover every selected group, hidden or not.') }}
        </span>
    </div>

    <div class="form-group">
        <label for="card_min_width-{{ $id }}" class="control-label">{{ __('Minimum card width') }}</label>
        <input type="number" step="1" min="120" max="320" class="form-control"
               name="card_min_width" id="card_min_width-{{ $id }}" value="{{ $card_min_width }}">
        <span class="help-block">{{ __('Used by the Cards layout. Recommended: 150-220. Tiles size themselves.') }}</span>
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
