@include('widgets.partials.nmsdw-style')

@php
    $hasDown = $total_down > 0;
    $singleGroup = $groups->count() === 1 ? $groups->first() : null;

    // 'compact' is both a display mode and a density. As a display mode it means
    // "list, but denser", so fold it into the density and render the list.
    $effectiveLayout = $layout === 'compact' ? 'list' : $layout;
    $effectiveDensity = ($layout === 'compact' || $density === 'compact') ? 'compact' : 'comfortable';

    $groupUrl = function ($group) {
        return url('/devices/group=' . $group->id . ($group->down_count > 0 ? '/state=down' : ''));
    };
@endphp

<div class="nmsdw-widget nmsdw-dgdc {{ $effectiveDensity === 'compact' ? 'nmsdw-compact' : '' }}">
    @if(! $has_selection)
        @include('widgets.partials.nmsdw-empty', [
            'message' => __('No device groups selected.'),
            'hint' => __('Edit this widget and choose one or more device groups to monitor.'),
        ])
    @elseif($groups->isEmpty())
        @include('widgets.partials.nmsdw-empty', [
            'message' => __('No accessible device groups.'),
            'hint' => __('The selected groups no longer exist, or you do not have access to them.'),
        ])
    @else
        @if($show_header)
            <div class="nmsdw-head">{{ __('Device group status') }}</div>
            <div class="nmsdw-sub">
                {{ trans_choice(':count group|:count groups', $groups->count(), ['count' => $groups->count()]) }}
                @if($hasDown)
                    &middot; {{ __(':count with devices down', ['count' => $affected_groups]) }}
                @else
                    &middot; {{ __('all healthy') }}
                @endif
            </div>
        @endif

        {{-- Grand total banner. Shown for a single group too, where it doubles as the hero. --}}
        @if($show_total || $singleGroup)
            @php($bannerGroup = $singleGroup)
            <a class="nmsdw-banner"
               @if($bannerGroup) href="{{ $groupUrl($bannerGroup) }}" @endif
               style="background: {{ $hasDown ? $background_color : 'var(--nmsdw-surface)' }}; color: {{ $hasDown ? $text_color : 'inherit' }};">
                <span class="nmsdw-banner-icon"
                      style="background: {{ $hasDown ? 'rgba(255,255,255,.25)' : 'var(--nmsdw-ok)' }}; color: #fff;">
                    {{ $hasDown ? '!' : '✓' }}
                </span>
                <span>
                    <span class="nmsdw-banner-value">{{ $singleGroup ? $singleGroup->down_count : $total_down }}</span>
                    <span class="nmsdw-banner-label">
                        {{ $singleGroup ? $singleGroup->name : __('devices down') }}
                    </span>
                </span>
            </a>
        @endif

        @if($effectiveLayout === 'summary')
            <div class="nmsdw-note">
                {{ __(':affected of :total groups affected', [
                    'affected' => $affected_groups,
                    'total' => $groups->count(),
                ]) }}
            </div>
        @elseif(! $singleGroup)
            <div class="{{ $effectiveLayout === 'cards' ? 'nmsdw-cards' : 'nmsdw-rows' }}"
                 @if($effectiveLayout === 'cards')
                     style="grid-template-columns: repeat(auto-fill, minmax({{ $card_min_width }}px, 1fr));"
                 @endif>
                @foreach($groups as $group)
                    @php($groupDown = $group->down_count > 0)
                    <a class="nmsdw-row {{ $groupDown ? 'nmsdw-row-down' : 'nmsdw-row-ok' }}"
                       href="{{ $groupUrl($group) }}">
                        <span class="nmsdw-row-name">{{ $group->name }}</span>

                        @include('widgets.partials.nmsdw-pill', [
                            'status' => $groupDown ? 'critical' : 'ok',
                            'label' => $groupDown ? __('DOWN') : __('OK'),
                        ])

                        <span class="nmsdw-row-count">{{ $group->down_count }}</span>

                        @if($show_group_totals)
                            <span class="nmsdw-row-totals">
                                <span>{{ __(':count total', ['count' => $group->total_count]) }}</span><br>
                                <span>{{ __(':count healthy', ['count' => max(0, $group->total_count - $group->down_count)]) }}</span>
                            </span>
                        @endif
                    </a>
                @endforeach
            </div>
        @elseif($show_group_totals)
            <div class="nmsdw-note">
                {{ __(':total total', ['total' => $singleGroup->total_count]) }} &middot;
                {{ __(':healthy healthy', ['healthy' => max(0, $singleGroup->total_count - $singleGroup->down_count)]) }}
            </div>
        @endif
    @endif
</div>
