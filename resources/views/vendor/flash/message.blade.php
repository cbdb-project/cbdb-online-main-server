@foreach (session('flash_notification', collect())->toArray() as $message)
    @if ($message['overlay'])
        @include('flash::modal', [
            'modalClass' => 'flash-modal',
            'title'      => $message['title'],
            'body'       => $message['message']
        ])
    @else
        <div class="alert
                    alert-{{ $message['level'] }}
                    {{ $message['important'] ? 'alert-important' : '' }} alert-dismissible"
                    role="alert"
        >
            <button type="button"
                    class="close"
                    data-dismiss="alert"
                    aria-hidden="true"
            >&times;</button>
            <h4><i class="icon fa fa-check"></i> Alert!</h4>
            {!! $message['message'] !!}
        </div>
    @endif
@endforeach
{{ session()->forget('flash_notification') }}
