@extends('layouts.dashboard')

@section('content')
    @include('biogmains.banner')
    <div class="panel panel-default">
        <div class="panel-heading">地址清單</div>
        <div class="panel-body">
            <a href="{{ route('basicinformation.addresses.create', $basicinformation->c_personid) }}" class="btn btn-default">新增</a>
            <table class="table table-hover table-condensed">
                <caption>共查询到{{ $basicinformation->addresses_count }}条记录</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>地址類別</th>
                    <th>地名</th>
                    <th>始年</th>
                    <th>終年</th>
                    <th style="width: 120px">操作</th>
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
                                <a type="button" class="btn btn-sm btn-info" href="{{ route('basicinformation.addresses.edit', ['id' => $basicinformation->c_personid, 'addr' => $basicinformation->addresses[$i]->pivot->tts_sysno]) }}">edit</a>
                                <a href=""
                                   onclick="alert('确认删除');
                                event.preventDefault();
                               document.getElementById('delete-form-{{ $basicinformation->addresses[$i]->pivot->tts_sysno }}').submit();"
                                   class="btn btn-sm btn-danger">delete</a>

                            </div>
                            <form id="delete-form-{{ $basicinformation->addresses[$i]->pivot->tts_sysno }}" action="{{ route('basicinformation.addresses.destroy', ['id' => $basicinformation->c_personid, 'addr' => $basicinformation->addresses[$i]->pivot->tts_sysno]) }}" method="POST" style="display: none;">
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
@endsection
