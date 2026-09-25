{{-- Shared records preserve units, selected metadata and links in every layout. --}}
@php
    $kind = match ($layout) { 'tiles' => 'rtile', 'compact' => 'rrow', default => 'rcard' };
    $container = match ($layout) { 'tiles' => 'rtiles', 'compact' => 'rlist', default => 'rcards' };
@endphp
<div class="nmsdw-{{ $container }}" @if($container === 'rcards') style="grid-template-columns: repeat(auto-fill, minmax({{ $card_min_width ?? 220 }}px, 1fr));" @endif>
    @foreach($records as $record)
        <div class="nmsdw-record nmsdw-{{ $kind }} nmsdw-{{ $kind }}-{{ $record['status'] ?? 'info' }}">
            <div class="nmsdw-record-identity">
                @if(!empty($record['href']))
                    <a href="{{ $record['href'] }}">{!! $record['title'] !!}</a>
                @else
                    <span>{!! $record['title'] !!}</span>
                @endif
                @if(!empty($record['subtitle'])) <div class="nmsdw-sec">{{ $record['subtitle'] }}</div> @endif
                @if(array_key_exists('observed_at', $record))
                    @include('widgets.partials.nmsdw-data-age', ['timestamp' => $record['observed_at'], 'stale_after' => $record['stale_after'] ?? 900, 'age_label' => $record['age_label'] ?? 'Observed'])
                @endif
            </div>
            <div class="nmsdw-record-value">
                @if(isset($record['value'])) <span class="nmsdw-rvalue">{{ $record['value'] }}</span> @endif
                @if(!empty($record['unit'])) <span class="nmsdw-sec">{{ $record['unit'] }}</span> @endif
                @if(!empty($record['status'])) <span class="nmsdw-pill nmsdw-pill-{{ $record['status'] }}">{{ ucfirst($record['status']) }}</span> @endif
            </div>
            @if(isset($record['bar']))
                <span class="nmsdw-rcard-bar"><span class="nmsdw-rcard-fill" style="width: {{ max(0, min(100, (float) $record['bar'])) }}%"></span></span>
            @endif
            @if(!empty($record['meta']))
                <div class="nmsdw-rcard-meta nmsdw-record-meta">
                    @foreach($record['meta'] as [$label, $value])
                        <span><span class="nmsdw-rcard-metak">{{ $label }}</span> {{ $value }}</span>
                    @endforeach
                </div>
            @endif
            @if(!empty($record['graph'])) <div class="nmsdw-record-graph">{!! $record['graph'] !!}</div> @endif
            @if(!empty($record['links']))
                <div class="nmsdw-record-links">
                    @foreach($record['links'] as $link) <a href="{{ $link['href'] }}">{{ $link['label'] }}</a> @endforeach
                </div>
            @endif
        </div>
    @endforeach
</div>
