@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-body">
            <p class="text-muted">以下列出目前系統支援的檢視表（View_*），點選可直接進入對應的 `/view/{key}` 頁面。</p>

            <div class="table-responsive">
                <table class="table table-striped table-bordered table-sm">
                    <thead>
                    <tr>
                        <th style="width: 28%">檢視名稱 (ENG)</th>
                        <th style="width: 24%">檢視名稱 (CHN)</th>
                        <th>說明</th>
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
