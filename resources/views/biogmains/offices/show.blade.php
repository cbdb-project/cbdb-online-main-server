@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                @include('biogmains.banner')
                <div class="panel panel-default">
                    <div class="panel-heading">官名清單</div>

                    <div class="panel-body">
                        <table class="table table-hover table-condensed">
                            <caption>共查询到{{ $basicinformation->offices_count }}条记录</caption>
                            <thead>
                            <tr>
                                <th>序號</th>
                                <th>sequence</th>
                                <th>posting_id</th>
                                <th>官名</th>
                                <th>地名</th>
                                <th>始年</th>
                                <th>終年</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($basicinformation->offices as $key=>$value)
                                <tr>
                                    <td>{{ $key+1 }}</td>
                                    <td>{{ $value->pivot->c_sequence }}</td>
                                    <td>{{ $value->pivot->c_posting_id }}</td>
                                    <td>{!! $value->c_office_pinyin. '<br>'. $value->c_office_chn !!}</td>
                                    <td>{{ $post2addr[$value->pivot->c_posting_id] }}</td>
                                    <td>{{ $value->pivot->c_firstyear }}</td>
                                    <td>{{ $value->pivot->c_lastyear }}</td>
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
