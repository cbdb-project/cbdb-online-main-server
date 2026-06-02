@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-body">
            <p class="text-muted">{{ __('common.view_list_desc') }}</p>

            <div class="table-responsive">
                <table class="table table-striped table-bordered table-sm">
                    <thead>
                    <tr>
                        <th style="width: 28%">{{ __('common.view_name_eng') }}</th>
                        <th style="width: 24%">{{ __('common.view_name_chn') }}</th>
                        <th>{{ __('common.description') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($views as $view)
                        <tr>
                            <td>
                                <a href="{{ route('view.show', $view['key']) }}">
                                    <code>{{ $view['primary_alias'] }}</code>
                                </a>
                                @if(!empty($view['aliases']))
                                    @php($extra = array_slice($view['aliases'], 1))
                                    @if(count($extra) > 0)
                                        <div class="text-muted">{{ implode(', ', $extra) }}</div>
                                    @endif
                                @endif
                            </td>
                            <td>{{ $view['title'] }}</td>
                            <td>{{ $view['description'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('js')
@endsection
