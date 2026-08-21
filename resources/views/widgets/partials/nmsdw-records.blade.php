{{--
    Generic renderer for the cards, compact and tiles layouts.

    Every list-shaped widget reduces its rows to the same neutral record shape, so the
    alternative layouts are written once here instead of eleven times.

    Expects:
      $records  list of:
                  title     string|Htmlable   primary label (device, group, peer)
                  subtitle  ?string           secondary label (interface, sensor)
                  value     ?string           the headline metric, pre-formatted
                  unit      ?string           small caption under the value
                  status    ?string           ok|warning|critical|info|unknown
                  bar       ?float            0-100, drawn under the value
                  meta      ?array            [[label, value], ...]
                  href      ?string           makes the whole record clickable
      $layout   cards|compact|tiles
      $card_min_width  int, cards layout only
--}}
@php
    $tag = fn (?string $href): string => $href ? 'a' : 'div';
@endphp

@if($layout === 'tiles')
    <div class="nmsdw-rtiles">
        @foreach($records as $record)
            <{{ $tag($record['href'] ?? null) }}
                class="nmsdw-rtile nmsdw-rtile-{{ $record['status'] ?? 'info' }}"
                @if(!empty($record['href'])) href="{{ $record['href'] }}" @endif
                title="{{ strip_tags((string) $record['title']) }}@if(!empty($record['value'])) — {{ $record['value'] }}@endif">
                <span class="nmsdw-rtile-value">{{ $record['value'] ?? '' }}</span>
                <span class="nmsdw-rtile-name">{{ strip_tags((string) $record['title']) }}</span>
            </{{ $tag($record['href'] ?? null) }}>
        @endforeach
    </div>

@elseif($layout === 'compact')
    <div class="nmsdw-rlist">
        @foreach($records as $record)
            <{{ $tag($record['href'] ?? null) }}
                class="nmsdw-rrow nmsdw-rrow-{{ $record['status'] ?? 'info' }}"
                @if(!empty($record['href'])) href="{{ $record['href'] }}" @endif>
                <span class="nmsdw-rdot"></span>
                <span class="nmsdw-rname">
                    {!! $record['title'] !!}
                    @if(!empty($record['subtitle']))
                        <span class="nmsdw-sec">{{ $record['subtitle'] }}</span>
                    @endif
                </span>
                @if(isset($record['bar']))
                    <span class="nmsdw-rbar">
                        <span class="nmsdw-rfill" style="width: {{ max(2, min(100, (float) $record['bar'])) }}%"></span>
                    </span>
                @endif
                <span class="nmsdw-rvalue">{{ $record['value'] ?? '' }}</span>
            </{{ $tag($record['href'] ?? null) }}>
        @endforeach
    </div>

@else
    <div class="nmsdw-rcards" style="grid-template-columns: repeat(auto-fill, minmax({{ $card_min_width ?? 220 }}px, 1fr));">
        @foreach($records as $record)
            <{{ $tag($record['href'] ?? null) }}
                class="nmsdw-rcard nmsdw-rcard-{{ $record['status'] ?? 'info' }}"
                @if(!empty($record['href'])) href="{{ $record['href'] }}" @endif>
                <span class="nmsdw-rcard-title">
                    {!! $record['title'] !!}
                </span>
                @if(!empty($record['subtitle']))
                    <span class="nmsdw-rcard-sub">{{ $record['subtitle'] }}</span>
                @endif

                @if(!empty($record['value']))
                    <span class="nmsdw-rcard-value">{{ $record['value'] }}</span>
                @endif
                @if(!empty($record['unit']))
                    <span class="nmsdw-rcard-unit">{{ $record['unit'] }}</span>
                @endif

                @if(isset($record['bar']))
                    <span class="nmsdw-rcard-bar">
                        <span class="nmsdw-rcard-fill" style="width: {{ max(2, min(100, (float) $record['bar'])) }}%"></span>
                    </span>
                @endif

                @if(!empty($record['meta']))
                    <span class="nmsdw-rcard-meta">
                        @foreach($record['meta'] as [$label, $value])
                            <span><span class="nmsdw-rcard-metak">{{ $label }}</span> {{ $value }}</span>
                        @endforeach
                    </span>
                @endif
            </{{ $tag($record['href'] ?? null) }}>
        @endforeach
    </div>
@endif
