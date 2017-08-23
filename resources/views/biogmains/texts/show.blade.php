@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                @include('biogmains.banner')
                <div class="panel panel-default">
                    <div class="panel-heading">著述清單</div>

                    <div class="panel-body">
                        <table class="table table-hover table-condensed">
                            <caption>共查询到{{ $basicinformation->texts_count }}条记录</caption>
                            <thead>
                            <tr>
                                <th>序號</th>
                                <th>書名</th>
                                <th>著述角色</th>
                            </tr>
                            </thead>
                            <tbody>
                            @for ($i = 0; $i < $basicinformation->texts_count; $i++)
                                <tr>
                                    <td>{{ $i+1 }}</td>
                                    <td>{{ $basicinformation->texts[$i]->c_title_chn }}</td>
                                    <td>{{ $basicinformation->texts_role[$i]->c_role_desc_chn }}</td>
                                </tr>
                            @endfor
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
