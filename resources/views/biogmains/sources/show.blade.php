@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                @include('biogmains.banner')
                <div class="panel panel-default">
                    <div class="panel-heading">出处</div>

                    <div class="panel-body">
                        <table class="table table-hover table-condensed">
                            <caption>共查询到{{ $basicinformation->sources_count }}条记录</caption>
                            <thead>
                            <tr>
                                <th>序號</th>
                                <th>出處</th>
                                <th>頁碼</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($basicinformation->sources as $key=>$value)
                                <tr>
                                    <td>{{ $key+=1 }}</td>
                                    <td>{{ $value->c_title_chn }}</td>
                                    <td>{{ $value->pivot->c_pages }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
