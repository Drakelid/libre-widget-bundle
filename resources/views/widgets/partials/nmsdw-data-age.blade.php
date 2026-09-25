@php($age = \Drakelid\NmsDashWidgets\Support\Freshness::describe($timestamp ?? null, $stale_after ?? 900))
<span class="nmsdw-sec {{ $age['stale'] ? 'nmsdw-stale' : '' }}" title="{{ $age['at'] ?? '' }}">
    {{ isset($age_label) ? str_replace('Observed', __($age_label), __($age['label'])) : __($age['label']) }}@if($age['stale'] && $age['at']) &middot; {{ __('stale') }}@endif
</span>
