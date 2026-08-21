{{--
    Layout and styling controls, shared by every widget in the bundle.

    Expects:
      $id       widget id, for unique DOM ids
      $layouts  list of layout keys this widget supports
      $layout, $density, $accent, $zebra, $show_header, $card_min_width

    Defaults reproduce each widget's original look, so these controls never change an
    existing placement until someone touches them.
--}}
@php
    $layoutLabels = [
        'table' => __('Table — dense columns'),
        'rows' => __('Rows — full-width status rows'),
        'cards' => __('Cards — one card per entry'),
        'compact' => __('Compact — one dense line per entry'),
        'tiles' => __('Tiles — colour squares, densest'),
    ];
@endphp

<div class="form-group">
    <label for="heading-{{ $id }}" class="control-label">{{ __('Heading inside the widget') }}</label>
    <input type="text" class="form-control" name="heading" id="heading-{{ $id }}"
           placeholder="{{ __('Use the default') }}" value="{{ $heading }}">
    <span class="help-block">
        {{ __('Optional. Replaces the heading shown in the widget body. The bar along the top of the widget is set by "Widget title" above.') }}
    </span>
</div>

<div class="form-group">
    <label for="layout-{{ $id }}" class="control-label">{{ __('Layout') }}</label>
    <select class="form-control" name="layout" id="layout-{{ $id }}">
        <option value="auto" @selected($layout === 'auto')>{{ __('Auto (follow widget size)') }}</option>
        @foreach($layouts as $option)
            <option value="{{ $option }}" @selected($layout === $option)>
                {{ $layoutLabels[$option] ?? $option }}
            </option>
        @endforeach
    </select>
    <span class="help-block">
        {{ __('Auto uses the dense line view when the widget is narrow and cards when it is wide.') }}
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
    <label for="accent-{{ $id }}" class="control-label">{{ __('Accent colour') }}</label>
    <select class="form-control" name="accent" id="accent-{{ $id }}">
        <option value="default" @selected($accent === 'default')>{{ __('Default') }}</option>
        <option value="blue" @selected($accent === 'blue')>{{ __('Blue') }}</option>
        <option value="green" @selected($accent === 'green')>{{ __('Green') }}</option>
        <option value="amber" @selected($accent === 'amber')>{{ __('Amber') }}</option>
        <option value="red" @selected($accent === 'red')>{{ __('Red') }}</option>
        <option value="violet" @selected($accent === 'violet')>{{ __('Violet') }}</option>
        <option value="slate" @selected($accent === 'slate')>{{ __('Slate') }}</option>
    </select>
    <span class="help-block">
        {{ __('Tints neutral chrome such as bars and headings. Status colours stay green, amber and red so an alert always reads the same.') }}
    </span>
</div>

<div class="form-group">
    <label for="card_min_width-{{ $id }}" class="control-label">{{ __('Minimum card width') }}</label>
    <input type="number" step="1" min="120" max="480" class="form-control"
           name="card_min_width" id="card_min_width-{{ $id }}" value="{{ $card_min_width }}">
    <span class="help-block">{{ __('Used by the Cards layout. Recommended: 200-280.') }}</span>
</div>

<div class="checkbox">
    <label>
        <input type="hidden" name="zebra" value="0">
        <input type="checkbox" name="zebra" value="1" @checked((bool) $zebra)>
        {{ __('Striped rows') }}
    </label>
</div>

<div class="checkbox">
    <label>
        <input type="hidden" name="show_header" value="0">
        <input type="checkbox" name="show_header" value="1" @checked((bool) $show_header)>
        {{ __('Show the heading and filter summary inside the widget') }}
    </label>
    <span class="help-block">
        {{ __('The widget title bar is always shown; this is the extra line beneath it.') }}
    </span>
</div>
