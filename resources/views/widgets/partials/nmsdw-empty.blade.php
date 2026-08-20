{{-- Consistent "nothing matched" rendering. Expects $message, optional $hint. --}}
<div class="nmsdw-empty">
    <div>{{ $message }}</div>
    @isset($hint)
        <div class="nmsdw-note">{{ $hint }}</div>
    @endisset
</div>
