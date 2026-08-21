@extends('widgets.settings.base')

@section('form')
    <div class="form-group">
        <label for="title-{{ $id }}" class="control-label">{{ __('Widget title') }}</label>
        <input type="text" class="form-control" name="title" id="title-{{ $id }}"
               placeholder="{{ __('Offline Devices Map') }}" value="{{ $title }}">
        <span class="help-block">{{ __('Sets the bar along the top of the widget.') }}</span>
    </div>

    @include('widgets.partials.nmsdw-device-groups-field', [
        'id' => $id,
        'selected_device_groups' => $selected_device_groups,
        'placeholder' => __('All accessible devices'),
        'help' => __('Select any number of device groups. Devices in any selected group are plotted; a device in two groups is shown once. Leave empty for every accessible device.'),
    ])

    <div class="form-group">
        <label for="status-{{ $id }}" class="control-label">{{ __('Show devices') }}</label>
        <select class="form-control" name="status" id="status-{{ $id }}">
            <option value="0" @selected($status === '0')>{{ __('Down only') }}</option>
            <option value="0,1" @selected($status === '0,1')>{{ __('Up and down') }}</option>
            <option value="1" @selected($status === '1')>{{ __('Up only') }}</option>
        </select>
        <span class="help-block">
            {{ __('Devices under maintenance are plotted blue rather than red.') }}
        </span>
    </div>

    <div class="form-group">
        <label for="group_radius-{{ $id }}" class="control-label">{{ __('Clustering radius') }}</label>
        <input type="number" step="1" min="1" max="500" class="form-control"
               name="group_radius" id="group_radius-{{ $id }}" value="{{ $group_radius }}"
               placeholder="{{ __('LibreNMS default') }}">
        <span class="help-block">
            {{ __('Pixels. Smaller values split clusters apart sooner, which suits a map zoomed into one region.') }}
        </span>
    </div>

    <div class="form-group">
        <label for="init_lat-{{ $id }}" class="control-label">{{ __('Initial latitude') }}</label>
        <input type="number" step="any" min="-90" max="90" class="form-control"
               name="init_lat" id="init_lat-{{ $id }}" value="{{ $init_lat }}"
               placeholder="{{ __('LibreNMS default') }}">
    </div>

    <div class="form-group">
        <label for="init_lng-{{ $id }}" class="control-label">{{ __('Initial longitude') }}</label>
        <input type="number" step="any" min="-180" max="180" class="form-control"
               name="init_lng" id="init_lng-{{ $id }}" value="{{ $init_lng }}"
               placeholder="{{ __('LibreNMS default') }}">
    </div>

    <div class="form-group">
        <label for="init_zoom-{{ $id }}" class="control-label">{{ __('Initial zoom') }}</label>
        <input type="number" step="0.1" min="0" max="18" class="form-control"
               name="init_zoom" id="init_zoom-{{ $id }}" value="{{ $init_zoom }}"
               placeholder="{{ __('LibreNMS default') }}">
    </div>

    <div class="form-group">
        <label for="init_layer-{{ $id }}" class="control-label">{{ __('Map layer') }}</label>

        @if(empty($available_layers))
            {{-- init_map() only builds a layer control for the google, bing, mapquest or
                 esri engines. With none configured it adds a single OpenStreetMap layer
                 and ignores config.layer entirely, so offering choices here would be a
                 lie -- which is exactly how this setting came to look broken. --}}
            <select class="form-control" disabled>
                <option>{{ __('OpenStreetMap (the only layer available)') }}</option>
            </select>
            <input type="hidden" name="init_layer" value="{{ $init_layer }}">
            <span class="help-block">
                {{ __('This installation uses the built-in OpenStreetMap tiles, which provide a single layer, so there is nothing to choose between.') }}
                <br>
                {{ __('To enable Streets, Satellite and Topography, set the Mapping Engine to ESRI ArcGIS. It needs no API key.') }}
                @can('admin')
                    <br>
                    <a href="{{ url('settings/external/location') }}" target="_blank" rel="noopener">{{ __('Open Location Settings') }}</a>
                    {{ __('or run on the LibreNMS server:') }}
                @else
                    <br>
                    {{ __('An administrator can set this, on the LibreNMS server:') }}
                @endcan
                <code>lnms config:set geoloc.engine esri</code>
                <br>
                {{ __('Google Maps, Bing Maps and MapQuest also work but each need an API key. Selecting OpenStreetMap leaves a single layer, the same as leaving the engine unset.') }}
            </span>
        @else
            <select class="form-control" name="init_layer" id="init_layer-{{ $id }}">
                <option value="" @selected(! $init_layer)>{{ __('LibreNMS default') }}</option>
                @foreach($available_layers as $available)
                    <option value="{{ $available }}" @selected($init_layer === $available)>{{ __($available) }}</option>
                @endforeach
            </select>
            <span class="help-block">
                {{ __('Layers offered by the :engine engine configured for this installation.', ['engine' => $map_engine]) }}
            </span>
        @endif
    </div>

    <div class="checkbox">
        <label>
            <input type="hidden" name="hide_attribution" value="0">
            <input type="checkbox" name="hide_attribution" value="1" @checked((bool) $hide_attribution)>
            {{ __('Hide the attribution line') }}
        </label>
        <span class="help-block">
            {{ __('Removes the "Leaflet | Powered by Esri ..." credit along the bottom of the map. Map providers ask for that credit in their terms of use, so this is your call to make.') }}
        </span>
    </div>

    <div class="checkbox">
        <label>
            <input type="hidden" name="hide_zoom" value="0">
            <input type="checkbox" name="hide_zoom" value="1" @checked((bool) $hide_zoom)>
            {{ __('Hide the zoom buttons') }}
        </label>
        <span class="help-block">
            {{ __('The + and - controls. Scroll wheel zoom still works after clicking the map, and pinch zoom is unaffected.') }}
        </span>
    </div>

    <div class="checkbox">
        <label>
            <input type="hidden" name="fit_to_markers" value="0">
            <input type="checkbox" name="fit_to_markers" value="1" @checked((bool) $fit_to_markers)>
            {{ __('Zoom to fit the markers on first load') }}
        </label>
        <span class="help-block">
            {{ __('Off keeps the latitude, longitude and zoom above exactly as set. Either way, panning during a refresh is preserved.') }}
        </span>
    </div>
@endsection

@section('javascript')
    <script type="text/javascript">
        init_select2('#device_groups-{{ $id }}', 'device-group', {});
    </script>
@endsection
