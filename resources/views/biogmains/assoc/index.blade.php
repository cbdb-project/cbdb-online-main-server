@extends('layouts.dashboard')

@section('content')
    @include('biogmains.banner')
    @include('biogmains.defense')
    <div class="panel panel-default">
        <div class="panel-heading">社會關係清單</div>

        <div class="panel-body">
            <table class="table table-hover table-condensed">
                <a href="{{ route('basicinformation.assoc.create', $basicinformation->c_personid) }}" class="btn btn-default pull-right">新增</a>
                <caption>共查询到{{ $basicinformation->assoc_count }}条记录</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>sequence</th>
                    <th>社會關係類別</th>
                    <th>社會關係人</th>
                    <th>作品標題</th>
                    <th style="width: 120px">操作</th>
                </tr>
                </thead>
                <tbody>
                @foreach($basicinformation->assoc as $key=>$value)
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>
                            @if($assoc_name[$key])
                                {{ $assoc_name[$key]['c_sequence'] }}
                            @endif
                        </td>
                        <td>{{ $value->c_assoc_desc_chn }}</td>
                        <td>
                            @if($assoc_name[$key])
                                <a href="{{ route('basicinformation.edit', $assoc_name[$key]['c_personid']) }}" target="_blank">{{ $assoc_name[$key]['assoc_name'] }}</a></td>
                            @endif
                        <td>{{ $value->pivot->c_text_title }}</td>
                        <td>
                            <div class="btn-group">
                            @php
                            $value->pivot->c_text_title = unionPKDef($value->pivot->c_text_title);
                            @endphp
                                <a type="button" class="btn btn-sm btn-info" href="{{ route('basicinformation.assoc.edit', ['id' => $basicinformation->c_personid, 'id_' => $value->pivot->c_personid."-".$value->pivot->c_assoc_code."-".$value->pivot->c_assoc_id."-".$value->pivot->c_kin_code."-".$value->pivot->c_kin_id."-".$value->pivot->c_assoc_kin_code."-".$value->pivot->c_assoc_kin_id."-".$value->pivot->c_text_title]) }}">edit</a>
                                <a href=""
                                   onclick="
                                           let msg = '您真的确定要删除吗？\n\n请确认！';
                                           if (confirm(msg)===true){
                                               event.preventDefault();
                                               document.getElementById('delete-form-{{ $value->pivot->c_personid."-".$value->pivot->c_assoc_code."-".$value->pivot->c_assoc_id."-".$value->pivot->c_kin_code."-".$value->pivot->c_kin_id."-".$value->pivot->c_assoc_kin_code."-".$value->pivot->c_assoc_kin_id."-".$value->pivot->c_text_title }}').submit();
                                           }else{
                                                return false;
                                           }
                                           "
                                   class="btn btn-sm btn-danger">delete</a>

                            </div>
                            <form id="delete-form-{{ $value->pivot->c_personid."-".$value->pivot->c_assoc_code."-".$value->pivot->c_assoc_id."-".$value->pivot->c_kin_code."-".$value->pivot->c_kin_id."-".$value->pivot->c_assoc_kin_code."-".$value->pivot->c_assoc_kin_id."-".$value->pivot->c_text_title }}" action="{{ route('basicinformation.assoc.destroy', ['id' => $basicinformation->c_personid, 'id_' => $value->pivot->c_personid."-".$value->pivot->c_assoc_code."-".$value->pivot->c_assoc_id."-".$value->pivot->c_kin_code."-".$value->pivot->c_kin_id."-".$value->pivot->c_assoc_kin_code."-".$value->pivot->c_assoc_kin_id."-".$value->pivot->c_text_title]) }}" method="POST" style="display: none;">
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
