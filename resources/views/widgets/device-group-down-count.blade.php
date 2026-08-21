@include('widgets.partials.nmsdw-style')

@php
    $hasDown = $total_down > 0;
    $singleGroup = $groups->count() === 1 ? $groups->first() : null;

    /*
     * `compact` is both a display mode and a density. As a display mode it now renders
     * its own single-line layout rather than a squeezed list, but it still forces
     * compact density so the two settings stay consistent.
     */
    $layoutName = $layout;
    $isCompactDensity = ($layoutName === 'compact' || $density === 'compact');

    // Widest bar in view, so proportions are readable even when nothing is badly down.
    $peakPercent = max(1.0, (float) $groups->max('down_percent'));

    $groupUrl = fn ($group) => url('/devices/group=' . $group->id . ($group->down_count > 0 ? '/state=down' : ''));
    $statusOf = fn ($group) => $group->down_count > 0 ? 'down' : 'ok';
@endphp

<div class="nmsdw-widget nmsdw-dgdc nmsdw-dgdc-{{ $layoutName }} nmsdw-accent-{{ $accent }} {{ $isCompactDensity ? 'nmsdw-compact' : '' }} {{ $zebra ? 'nmsdw-zebra' : '' }}">
    @if(! $has_selection)
        @include('widgets.partials.nmsdw-empty', [
            'message' => __('No device groups selected.'),
            'hint' => __('Edit this widget and choose one or more device groups to monitor.'),
        ])
    @elseif($group_count === 0)
        @include('widgets.partials.nmsdw-empty', [
            'message' => __('No accessible device groups.'),
            'hint' => __('The selected groups no longer exist, or you do not have access to them.'),
        ])
    @else
        @if($show_header)
            <div class="nmsdw-dgdc-header">
                <div>
                    <div class="nmsdw-head">{{ $heading ?: __('Device group status') }}</div>
                    <div class="nmsdw-sub">
                        {{ __(':count groups', ['count' => $group_count]) }}
                        &middot;
                        @if($hasDown)
                            {{ __(':count affected', ['count' => $affected_groups]) }}
                        @else
                            {{ __('all healthy') }}
                        @endif
                        @if($total_devices > 0)
                            &middot; {{ __(':count devices', ['count' => $total_devices]) }}
                        @endif
                    </div>
                </div>
                @include('widgets.partials.nmsdw-pill', [
                    'status' => $hasDown ? 'critical' : 'ok',
                    'label' => $hasDown ? __('DOWN') : __('OK'),
                ])
            </div>
        @endif

        {{-- Hero banner. Doubles as the whole widget in summary mode. --}}
        @if($show_total || $singleGroup || $layoutName === 'summary')
            @php($heroGroup = $singleGroup)
            <a class="nmsdw-hero nmsdw-hero-{{ $hasDown ? 'down' : 'ok' }}"
               @if($heroGroup) href="{{ $groupUrl($heroGroup) }}" @endif
               @if($hasDown) style="--nmsdw-hero-bg: {{ $background_color }}; --nmsdw-hero-fg: {{ $text_color }};" @endif>
                <span class="nmsdw-hero-icon">{{ $hasDown ? '!' : '✓' }}</span>
                <span class="nmsdw-hero-body">
                    <span class="nmsdw-hero-value">{{ $heroGroup ? $heroGroup->down_count : $total_down }}</span>
                    <span class="nmsdw-hero-label">
                        {{ $heroGroup ? $heroGroup->name : __('devices down') }}
                    </span>
                </span>
                <span class="nmsdw-hero-meta">
                    @if($heroGroup)
                        <span>{{ __(':count total', ['count' => $heroGroup->total_count]) }}</span>
                        <span>{{ __(':count healthy', ['count' => $heroGroup->healthy_count]) }}</span>
                    @else
                        <span>{{ __(':a of :b groups', ['a' => $affected_groups, 'b' => $group_count]) }}</span>
                        @if($total_devices > 0)
                            <span>{{ number_format(($total_down / max(1, $total_devices)) * 100, 1) }}% {{ __('of estate') }}</span>
                        @endif
                    @endif
                </span>
            </a>
        @endif

        @if($layoutName === 'summary')
            @if($hasDown && $worst_group)
                <div class="nmsdw-note">
                    {{ __('Worst: :name with :count down', [
                        'name' => $worst_group->name,
                        'count' => $worst_group->down_count,
                    ]) }}
                </div>
            @endif

        @elseif($singleGroup)
            {{-- One group: the hero already says everything a list would repeat. --}}
            @if($show_group_totals)
                @include('widgets.partials.nmsdw-meter', [
                    'percent' => $singleGroup->health_percent,
                    'status' => $singleGroup->down_count > 0 ? 'warning' : 'ok',
                    'caption' => __(':healthy of :total devices up', [
                        'healthy' => $singleGroup->healthy_count,
                        'total' => $singleGroup->total_count,
                    ]),
                ])
            @endif

        @elseif($groups->isEmpty())
            @include('widgets.partials.nmsdw-empty', ['message' => __('All selected groups are healthy.')])

        @elseif($layoutName === 'tiles')
            {{-- Dense status squares. Built for wall displays with many groups. --}}
            <div class="nmsdw-tilegrid">
                @foreach($groups as $group)
                    <a class="nmsdw-gtile nmsdw-gtile-{{ $statusOf($group) }}"
                       href="{{ $groupUrl($group) }}"
                       title="{{ $group->name }} — {{ $group->down_count }}/{{ $group->total_count }}">
                        <span class="nmsdw-gtile-value">{{ $group->down_count }}</span>
                        <span class="nmsdw-gtile-name">{{ $group->name }}</span>
                    </a>
                @endforeach
            </div>

        @elseif($layoutName === 'bars')
            {{-- Comparative view: bar length is the proportion of the group that is down. --}}
            <div class="nmsdw-bars">
                @foreach($groups as $group)
                    <a class="nmsdw-bar-row" href="{{ $groupUrl($group) }}">
                        <span class="nmsdw-bar-label">{{ $group->name }}</span>
                        <span class="nmsdw-bar-track">
                            <span class="nmsdw-bar-fill nmsdw-bar-{{ $statusOf($group) }}"
                                  style="width: {{ max(2, ($group->down_percent / $peakPercent) * 100) }}%"></span>
                        </span>
                        <span class="nmsdw-bar-value">
                            {{ $group->down_count }}
                            <span class="nmsdw-sec">{{ number_format($group->down_percent, 1) }}%</span>
                        </span>
                    </a>
                @endforeach
            </div>

        @elseif($layoutName === 'cards')
            <div class="nmsdw-cardgrid"
                 style="grid-template-columns: repeat(auto-fill, minmax({{ $card_min_width }}px, 1fr));">
                @foreach($groups as $group)
                    <a class="nmsdw-gcard nmsdw-gcard-{{ $statusOf($group) }}" href="{{ $groupUrl($group) }}">
                        <span class="nmsdw-gcard-name">{{ $group->name }}</span>
                        <span class="nmsdw-gcard-value">{{ $group->down_count }}</span>
                        <span class="nmsdw-gcard-unit">{{ __('down') }}</span>
                        <span class="nmsdw-gcard-bar">
                            <span class="nmsdw-gcard-fill nmsdw-bar-{{ $group->down_count > 0 ? 'part' : 'ok' }}"
                                  style="width: {{ max(0, min(100, $group->health_percent)) }}%"></span>
                        </span>
                        @if($show_group_totals)
                            <span class="nmsdw-gcard-meta">
                                {{ __(':healthy of :total up', [
                                    'healthy' => $group->healthy_count,
                                    'total' => $group->total_count,
                                ]) }}
                            </span>
                        @endif
                    </a>
                @endforeach
            </div>

        @elseif($layoutName === 'compact')
            {{-- One line per group, no wasted vertical space. --}}
            <div class="nmsdw-clist">
                @foreach($groups as $group)
                    <a class="nmsdw-crow nmsdw-crow-{{ $statusOf($group) }}" href="{{ $groupUrl($group) }}">
                        <span class="nmsdw-cdot"></span>
                        <span class="nmsdw-cname">{{ $group->name }}</span>
                        <span class="nmsdw-cbar">
                            <span class="nmsdw-cfill nmsdw-bar-{{ $group->down_count > 0 ? 'part' : 'ok' }}"
                                  style="width: {{ max(0, min(100, $group->health_percent)) }}%"></span>
                        </span>
                        <span class="nmsdw-cvalue">{{ $group->down_count }}<span class="nmsdw-sec">/{{ $group->total_count }}</span></span>
                    </a>
                @endforeach
            </div>

        @else
            {{-- Default list: the layout the original widget shipped, with a proportion bar. --}}
            <div class="nmsdw-rows">
                @foreach($groups as $group)
                    <a class="nmsdw-row nmsdw-row-{{ $statusOf($group) }}" href="{{ $groupUrl($group) }}">
                        <span class="nmsdw-row-main">
                            <span class="nmsdw-row-name">{{ $group->name }}</span>
                            @if($show_group_totals)
                                {{-- Health meter: full bar means every device in the group is up. --}}
                                <span class="nmsdw-row-bar">
                                    <span class="nmsdw-row-fill nmsdw-bar-{{ $group->down_count > 0 ? 'part' : 'ok' }}"
                                          style="width: {{ max(0, min(100, $group->health_percent)) }}%"></span>
                                </span>
                            @endif
                        </span>

                        @include('widgets.partials.nmsdw-pill', [
                            'status' => $statusOf($group) === 'down' ? 'critical' : 'ok',
                            'label' => $statusOf($group) === 'down' ? __('DOWN') : __('OK'),
                        ])

                        <span class="nmsdw-row-count">{{ $group->down_count }}</span>

                        @if($show_group_totals)
                            <span class="nmsdw-row-totals">
                                <span>{{ __(':count total', ['count' => $group->total_count]) }}</span><br>
                                <span>{{ __(':count healthy', ['count' => $group->healthy_count]) }}</span>
                            </span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif

        @if($hidden_count > 0)
            <div class="nmsdw-note">
                {{ __(':count healthy groups hidden.', ['count' => $hidden_count]) }}
            </div>
        @endif
    @endif
</div>
