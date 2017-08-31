@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                @include('biogmains.banner')
                <div class="panel panel-default">
                    <div class="panel-heading">社會區分清單</div>

                    <div class="panel-body">
                        <table class="table table-hover table-condensed">
                            <caption>共查询到{{ $basicinformation->statuses_count }}条记录</caption>
                            <thead>
                            <tr>
                                <th>序號</th>
                                <th>SEQUENCE</th>
                                <th>社會區分(英)</th>
                                <th>社會區分(中)</th>
                                <th>始年</th>
                                <th>終年</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($basicinformation->statuses as $key=>$value)
                                <tr>
                                    <td>{{ $key+1 }}</td>
                                    <td>{{ $value->pivot->c_sequence }}</td>
                                    <td>{{ $value->c_status_desc }}</td>
                                    <td>{{ $value->c_status_desc_chn }}</td>
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
