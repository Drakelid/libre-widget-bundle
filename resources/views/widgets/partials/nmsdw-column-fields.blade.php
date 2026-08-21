{{--
    Column visibility checkboxes.

    Expects:
      $id                widget id, for unique DOM ids
      $column_defs       key => [label, default, required]
      $column_visible    key => bool, the current state

    Required columns are shown checked and disabled: hiding the device, or the value the
    widget exists to report, would leave rows that say nothing. A disabled checkbox posts
    nothing, so each one is backed by a hidden input to keep it in the saved list.
--}}
<div class="form-group">
    <label class="control-label">{{ __('Columns') }}</label>
    <span class="help-block" style="margin-top: 0;">
        {{ __('Uncheck a column to hide it. Greyed out columns are always shown.') }}
    </span>

    @foreach($column_defs as $key => $definition)
        @php([$label, $default, $required] = $definition)
        <div class="checkbox">
            <label>
                @if($required)
                    <input type="hidden" name="columns[]" value="{{ $key }}">
                    <input type="checkbox" checked disabled>
                    {{ __($label) }}
                    <span class="nmsdw-sec">{{ __('always shown') }}</span>
                @else
                    <input type="checkbox" name="columns[]" value="{{ $key }}"
                           id="col_{{ $key }}-{{ $id }}"
                           @checked($column_visible[$key] ?? $default)>
                    {{ __($label) }}
                @endif
            </label>
        </div>
    @endforeach
</div>
