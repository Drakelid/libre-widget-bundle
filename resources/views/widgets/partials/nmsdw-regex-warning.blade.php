{{--
    Non-blocking notice that a user supplied regex was ignored.
    Expects $problems: array of ['label' => ..., 'reason' => ...].
--}}
@if(!empty($problems))
    <div class="nmsdw-alert nmsdw-alert-warn">
        @foreach($problems as $problem)
            <div><strong>{{ $problem['label'] }}:</strong> {{ $problem['reason'] }}</div>
        @endforeach
    </div>
@endif
