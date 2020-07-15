@extends('layouts.dashboard')

@section('content')
@include('biogmains.defense')
    <div class="panel panel-default">
        <div class="panel-heading">最近修改記錄</div>
        <div class="panel-body">
            <table class="table table-bordered table-striped">
                <p>* 修改类型 0表示crowdsourcing記錄，1表示新增，3表示修改，4表示删除<br />
                * 狀態 0代表是專業用戶修改的記錄，1代表crowdsourcing記錄並且已經被插入數據庫。
                </p>
                <thead>
                <tr>
                    <th>人物</th>
                    <th>修改资源</th>
                    <th>修改值</th>
                    <th>资源tts</th>
                    <th>修改类型</th>
                    <th>修改人</th>
                    <th>錄入时间</th>
                    <th>修改时间</th>
                    <th>狀態</th>
                </tr>
                </thead>
                <tbody>
                    @foreach($lists as $item)
@php
$item->resource_id = unionPKDef($item->resource_id);
$item->resource_data = unionPKDef($item->resource_data);
@endphp
                        <tr>
                            <td>
                            <a href="/basicinformation/
@php
  $a = $item->resource;
  $id = $item->c_personid;
  $res_id = $item->resource_id;
if($item->op_type == 4) { echo $id; }
else {
  switch ($a) {
    case "BIOG_MAIN":
      echo $id;
      break;
    case "BIOG_ADDR_DATA":
      echo $id."/addresses/".$res_id;
      break;
    case "ALTNAME_DATA":
      echo $id."/altnames/".$res_id;
      break;
    case "TEXT_DATA":
      echo $id."/texts/".$res_id;
      break;
    case "POSTED_TO_OFFICE_DATA":
      echo $id."/offices/".$res_id;
      break;
    case "ENTRY_DATA":
      echo $id."/entries/".$res_id;
      break;
    case "EVENTS_DATA":
      echo $id."/events/".$res_id;
      break;
    case "STATUS_DATA":
      echo $id."/statuses/".$res_id;
      break;
    case "KIN_DATA":
      echo $id."/kinship/".$res_id;
      break;
    case "ASSOC_DATA":
      $res_id = str_replace("/","(slash)",$res_id);
      echo $id."/assoc/".$res_id;
      break;
    case "POSSESSION_DATA":
      echo $id."/possession/".$res_id;
      break;
    case "BIOG_INST_DATA":
      echo $id."/socialinst/".$res_id;
      break;
    case "BIOG_SOURCE_DATA":
      echo $id."/sources/".$res_id;
      break;
    default:
      echo $id;
  }
}
//20200714不能直接轉回去, 版型會消失, 需要使用專屬的轉換函式.
$item->resource_id = unionPKDef_decode_for_convert($item->resource_id);
$item->resource_data = unionPKDef_decode_for_convert($item->resource_data);
@endphp
@endphp
/edit">{{ $item->biogmain->c_name_chn.' '.$item->biogmain->c_name }}</a>
                            </td>
                            <td>{{ $item->resource }}</td>
                            <td>
                                <button type="button" class="btn btn-info" data-toggle="modal" data-target="#myModal{{ $item->id }}">resource_data</button>
                                <button type="button" class="btn btn-info" data-toggle="modal" data-target="#myModal-mapping{{ $item->id }}">compare</button>
                            </td>
                            <td>{{ $item->resource_id }}</td>
                            <td>{{ $item->op_type }}</td>
                            <td>{{ $item->user->name }}</td>
                            <td>{{ $item->created_at }}</td>
                            <td>{{ $item->updated_at }}</td>
                            <td>{{ $item->crowdsourcing_status }}</td>
                        </tr>
                        <!--Start-->
                        <div id="myModal{{ $item->id }}" class="modal fade" role="dialog">
                          <div class="modal-dialog">
                            <!-- Modal content-->
                            <div class="modal-content">
                              <div class="modal-header">
                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                <h4 class="modal-title">resource_data</h4>
                              </div>
                              <div class="modal-body" style="word-break: break-all;">
                                <textarea rows="16" cols="90">{{ $item->resource_data }}</textarea>
                              </div>
                              <div class="modal-footer">
                                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                              </div>
                            </div>
                          </div>
                        </div>
                        <!--End-->
                        <!--Start-->
                        <div id="myModal-mapping{{ $item->id }}" class="modal fade" role="dialog">
                          <div class="modal-dialog">
                            <!-- Modal content-->
                            <div class="modal-content">
                              <div class="modal-header">
                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                <h4 class="modal-title">compare</h4>
                              </div>
                              <div class="modal-body" style="word-break: break-all;">
                                <div>
                                @if (!empty($item->resource_original))
                                    欄位比對的結果：<br/>
                                    {!! $item->resource_original !!}
                                @else
                                    沒有比對紀錄
                                @endif
                                </div>
                              </div>
                              <div class="modal-footer">
                                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                              </div>
                            </div>
                          </div>
                        </div>
                        <!--End-->
                    @endforeach
                </tbody>
            </table>
            <div class="pull-right">
                {{ $lists->links() }}
            </div>
        </div>
    </div>

@endsection

@section('js')

@endsection
