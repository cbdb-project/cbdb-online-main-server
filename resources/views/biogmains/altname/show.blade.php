@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                @include('biogmains.banner')
                <div class="panel panel-default">
                    <div class="panel-heading">别名</div>

                    <div class="panel-body">
                        <table class="table table-hover table-condensed">
                            <caption>共查询到{{ $basicinformation->altnames_count }}条记录</caption>
                            <thead>
                            <tr>
                                <th>序號</th>
                                <th>別名拼音</th>
                                <th>別名漢字</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($basicinformation->altnames as $key=>$value)
                                <tr>
                                    <td>{{ $key+1 }}</td>
                                    <td>{{ $value->pivot->c_alt_name }}</td>
                                    <td>{{ $value->pivot->c_alt_name_chn }}</td>
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
