@extends('widgets.settings.base')

@section('form')
    <div class="form-group">
        <label for="title-{{ $id }}" class="control-label">{{ __('Widget title') }}</label>
        <input type="text" class="form-control" name="title" id="title-{{ $id }}"
               placeholder="{{ __('Recently Added Devices') }}" value="{{ $title }}">
    </div>

    <div class="form-group">
        <label for="device_count-{{ $id }}" class="control-label">{{ __('Number of devices to show') }}</label>
        <input type="number" step="1" min="1" max="50" class="form-control"
               name="device_count" id="device_count-{{ $id }}" value="{{ $device_count }}">
    </div>
    <hr>

    @include('widgets.partials.nmsdw-presentation-fields', [
        'id' => $id,
        'layouts' => $layouts,
        'layout' => $layout,
        'density' => $density,
        'accent' => $accent,
        'zebra' => $zebra,
        'show_header' => $show_header,
        'card_min_width' => $card_min_width,
    ])

@endsection
