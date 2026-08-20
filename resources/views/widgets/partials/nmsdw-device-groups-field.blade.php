{{--
    Device group multi-select backed by the plugin's own select2 endpoint.

    Expects:
      $id                     widget id (for unique DOM ids)
      $selected_device_groups Collection of DeviceGroup models, in selection order
      $help                   help text under the field
      $placeholder            text shown when nothing is selected

    Every id is suffixed with the widget id. Two placements of the same widget can
    appear on one dashboard, and duplicate DOM ids make labels focus the wrong input.
--}}
<div class="form-group">
    <label for="device_groups-{{ $id }}" class="control-label">{{ __('Device groups') }}</label>
    <select multiple
            class="form-control"
            name="device_groups[]"
            id="device_groups-{{ $id }}"
            data-placeholder="{{ $placeholder ?? __('All device groups') }}">
        @foreach($selected_device_groups as $group)
            <option value="{{ $group->id }}" selected>{{ $group->name }}</option>
        @endforeach
    </select>
    <span class="help-block">{{ $help }}</span>
</div>

<script type="text/javascript">
    (function () {
        var selector = '#device_groups-{{ $id }}';

        // init_select2 is provided by LibreNMS. Guard so a change in core degrades to a
        // plain multi-select rather than a JS error that breaks the whole settings form.
        if (typeof init_select2 === 'function') {
            init_select2(selector, 'nmsdashwidgets-device-groups', {});
        } else if (window.jQuery && jQuery.fn.select2) {
            jQuery(selector).select2({
                ajax: {
                    url: '{{ url('ajax/select/nmsdashwidgets-device-groups') }}',
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return { term: params.term, page: params.page || 1 };
                    }
                },
                width: '100%',
                allowClear: true
            });
        }
    })();
</script>
