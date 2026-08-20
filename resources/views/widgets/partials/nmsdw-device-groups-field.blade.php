{{--
    Device group multi-select.

    Markup only. The select2 initialiser belongs in the settings blade's javascript
    section, which the base settings template yields after the form -- that is where
    core's own widget settings forms put theirs.

    Backed by core's ajax/select/device-group endpoint, which already applies
    authorize('viewAny') and hasAccess(), plus search and pagination. No reason to
    ship our own.

    Every id is suffixed with the widget id: two placements of the same widget can
    share a dashboard, and duplicate DOM ids make labels focus the wrong input.

    Expects: $id, $selected_device_groups, $help, optional $placeholder.
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
