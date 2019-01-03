@extends('layouts.dashboard')

@section('content')
    @include('biogmains.banner')
    <div class="panel panel-default">
        <div class="panel-heading">入仕清單</div>

        <div class="panel-body">
            <table class="table table-hover table-condensed">
                <a href="{{ route('basicinformation.entries.create', $basicinformation->c_personid) }}" class="btn btn-default pull-right">新增</a>
                <caption>共查询到{{ $basicinformation->entries_count }}条记录</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>sequence</th>
                    <th>入仕法</th>
                    <th style="width: 120px">操作</th>
                </tr>
                </thead>
                <tbody>
                @foreach($basicinformation->entries as $key=>$value)
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>{{ $value->pivot->c_sequence }}</td>
                        <td>{{ $value->c_entry_desc_chn }}</td>
                        <td>
                            <div class="btn-group">
                                @php($id_ = $value->pivot->c_personid."-".$value->pivot->c_entry_code."-".$value->pivot->c_sequence."-".$value->pivot->c_kin_code."-".$value->pivot->c_assoc_code."-".$value->pivot->c_kin_id."-".$value->pivot->c_year."-".$value->pivot->c_assoc_id."-".$value->pivot->c_inst_code."-".$value->pivot->c_inst_name_code)
                                <a type="button" class="btn btn-sm btn-info" href="{{ route('basicinformation.entries.edit', ['id' => $basicinformation->c_personid, 'id_' => $id_]) }}">edit</a>
                                <a href=""
                                   onclick="
                                           let msg = '您真的确定要删除吗？\n\n请确认！';
                                           if (confirm(msg)===true){
                                               event.preventDefault();
                                               document.getElementById('delete-form-{{ $id_ }}').submit();
                                           }else{
                                                return false;
                                           }"
                                   class="btn btn-sm btn-danger">delete</a>

                            </div>
                            <form id="delete-form-{{ $id_ }}" action="{{ route('basicinformation.entries.destroy', ['id' => $basicinformation->c_personid, 'id_' => $id_]) }}" method="POST" style="display: none;">
                                {{ method_field('DELETE') }}
                                {{ csrf_field() }}
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
