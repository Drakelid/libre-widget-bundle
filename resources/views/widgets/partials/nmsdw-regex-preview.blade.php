@if(in_array($preview_slug ?? '', ['uplink-utilization-overview', 'customer-port-status', 'top-device-temperatures', 'optical-light-levels'], true))
    <div class="form-group" id="filter-preview-{{ $id }}">
        <button type="button" class="btn btn-default nmsdw-preview-button">{{ __('Preview filters') }}</button>
        <div class="help-block" role="status" aria-live="polite"></div>
    </div>
    <script>
        (function () {
            var area = $('#filter-preview-{{ $id }}');
            var output = area.find('[role="status"]');
            var button = area.find('button');
            button.on('click', function () {
                var fields = area.closest('form').serializeArray();
                fields.push({name: 'id', value: {{ Js::from($id) }}});
                fields.push({name: 'widget_slug', value: {{ Js::from($preview_slug) }}});
                button.prop('disabled', true);
                output.text({{ Js::from(__('Checking filters…')) }});
                $.ajax({url: {{ Js::from(route('plugin.nmsdashwidgets.filter-preview')) }}, type: 'POST', data: fields})
                    .done(function (data) {
                        output.empty();
                        if (data.error) { output.text(data.error); return; }
                        $('<div>').text(data.matched === null ? {{ Js::from(__('Matching examples')) }} : data.matched + ' ' + {{ Js::from(__('matches before the display limit')) }}).appendTo(output);
                        (data.examples || []).forEach(function (example) { $('<div>').text(example).appendTo(output); });
                        (data.problems || []).forEach(function (problem) { $('<div>').text(problem.reason || problem).appendTo(output); });
                        $('<div>').text(data.note).appendTo(output);
                    })
                    .fail(function () { output.text({{ Js::from(__('Preview unavailable. Check the filter and try again.')) }}); })
                    .always(function () { button.prop('disabled', false); });
            });
        })();
    </script>
@endif
