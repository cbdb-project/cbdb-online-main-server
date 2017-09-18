@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                @include('biogmains.banner')
                <div class="panel panel-default">
                    <div class="panel-heading">地址清單</div>
                    <div class="panel-body">
                        <table class="table table-hover table-condensed">
                            <caption>共查询到{{ $basicinformation->addresses_count }}条记录</caption>
                            <thead>
                            <tr>
                                <th>序號</th>
                                <th>地址類別</th>
                                <th>地名</th>
                                <th>始年</th>
                                <th>終年</th>
                                <th>操作</th>
                            </tr>
                            </thead>
                            <tbody>
                            @for ($i = 0; $i < $basicinformation->addresses_count; $i++)
                                <tr>
                                    <td>{{ $i+1 }}</td>
                                    <td>{{ $basicinformation->addresses_type[$i]->c_addr_desc_chn }}</td>
                                    <td>{{ $basicinformation->addresses[$i]->c_name_chn }}</td>
                                    <td>{{ $basicinformation->addresses[$i]->pivot->c_firstyear }}</td>
                                    <td>{{ $basicinformation->addresses[$i]->pivot->c_lastyear }}</td>
                                    <td>
                                        <div class="btn-group">
                                            <a type="button" class="btn btn-sm btn-info" href="{{ route('basicinformation.addresses.edit', ['id' => $basicinformation->c_personid, 'addr' => 1]) }}">edit</a>
                                            <a href=""
                                               onclick="alert('确认删除');
                                            event.preventDefault();
                                           document.getElementById('delete-form').submit();"
                                               class="btn btn-sm btn-danger">delete</a>

                                        </div>
                                        <form id="delete-form" action="" method="POST" style="display: none;">
                                            {{ method_field('DELETE') }}
                                            {{ csrf_field() }}
                                        </form>
                                    </td>
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
