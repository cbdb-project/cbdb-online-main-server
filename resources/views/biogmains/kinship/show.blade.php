@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                @include('biogmains.banner')
                <div class="panel panel-default">
                    <div class="panel-heading">親屬清單</div>

                    <div class="panel-body">
                        <table class="table table-hover table-condensed">
                            <caption>共查询到{{ $basicinformation->kinship_count }}条记录</caption>
                            <thead>
                            <tr>
                                <th>序號</th>
                                <th>親屬關係類別</th>
                                <th>親戚姓名</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($basicinformation->kinship as $key=>$value)
                                <tr>
                                    <td>{{ $key+1 }}</td>
                                    <td>{{ $value->c_kinrel_chn. ' '. $value->c_kinrel_alt }}</td>
                                    <td>{{ $basicinformation->kinship_name[$key]->c_name_chn.' '.$basicinformation->kinship_name[$key]->c_name }}</td>
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
