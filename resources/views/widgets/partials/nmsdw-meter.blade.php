{{-- Proportional bar. Expects $percent (0-100), optional $status, optional $caption. --}}
<div class="nmsdw-meter nmsdw-meter-{{ $status ?? 'info' }}">
    <span class="nmsdw-meter-fill" style="width: {{ max(0, min(100, (float) $percent)) }}%"></span>
</div>
@isset($caption)
    <div class="nmsdw-temp-caption">{{ $caption }}</div>
@endisset
